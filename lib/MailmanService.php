<?php

class MailmanService
{
    private $rc;
    private $client;
    private $cache;
    private $mapper;
    private $guard;
    private $config;
    private $debugEntries = [];

    public function __construct(rcube $rc, MailmanClient $client, MailmanCache $cache, MailmanMapper $mapper, MailmanGuard $guard, array $config)
    {
        $this->rc = $rc;
        $this->client = $client;
        $this->cache = $cache;
        $this->mapper = $mapper;
        $this->guard = $guard;
        $this->config = $config;
    }

    public function getDashboardData($selectedListId = '')
    {
        $status = $this->getHealth();
        $lists = $status['ok'] ? $this->getMyLists() : [];
        $directory = $status['ok'] && $this->guard->canBrowseDirectory() ? $this->getDirectoryLists() : [];
        $selected = $selectedListId ? $this->getListDetail($selectedListId) : (reset($lists) ?: null);

        if ($selected && !empty($selected['id']) && empty($selected['membership'])) {
            foreach ($lists as $entry) {
                if (!empty($entry['id']) && $entry['id'] === $selected['id'] && !empty($entry['membership'])) {
                    $selected['membership'] = $entry['membership'];
                    break;
                }
            }
        }

        $this->debug('dashboard.summary', [
            'selected_list' => $selectedListId,
            'health_ok' => $status['ok'],
            'write_ok' => $status['write_ok'],
            'my_list_count' => count($lists),
            'directory_count' => count($directory),
            'identity_emails' => $this->mapper->getCurrentUserEmails(),
        ]);

        return [
            'health' => $status,
            'my_lists' => $lists,
            'directory_lists' => $directory,
            'selected' => $selected,
            'can_subscribe' => $this->guard->canSubscribe() && $status['write_ok'],
            'can_unsubscribe' => $this->guard->canUnsubscribe() && $status['write_ok'],
            'show_archives' => (bool) $this->config['show_archives'],
            'show_list_settings' => (bool) $this->config['show_list_settings'],
            'directory_enabled' => $this->guard->canBrowseDirectory(),
            'debug_enabled' => $this->isDebugEnabled(),
            'debug_entries' => $this->debugEntries,
        ];
    }

    public function getHealth()
    {
        $cached = $this->cache->get('health');
        if ($cached !== null) {
            $this->debug('health.cache_hit', $cached);
            return $cached;
        }

        if (!$this->client->isConfigured()) {
            $data = [
                'ok' => false,
                'write_ok' => false,
                'message' => 'mailman_not_configured',
            ];
            $this->debug('health.not_configured', [
                'api_url_present' => !empty($this->config['api_url']),
                'api_user_present' => !empty($this->config['api_user']),
                'api_password_present' => !empty($this->config['api_password']),
            ]);
            $this->cache->set('health', $data);
            return $data;
        }

        $probe = $this->probeHealth();
        $data = [
            'ok' => $probe['success'],
            'write_ok' => $probe['success'],
            'message' => $probe['success'] ? 'mailman_status_ok' : 'mailman_status_degraded',
        ];
        $this->cache->set('health', $data);

        return $data;
    }

    private function probeHealth()
    {
        $primary = $this->config['health_path'] ?? '/system';
        $fallback = $this->config['health_fallback_path'] ?? '';

        $probe = $this->client->get($primary);
        $this->debugResponse('health.probe', $probe, ['path' => $primary]);

        if ($probe['success']) {
            return $probe;
        }

        $status = (int) ($probe['status'] ?? 0);
        if ($status === 404 && $fallback !== '' && $fallback !== $primary) {
            $fallbackProbe = $this->client->get($fallback);
            $this->debugResponse('health.probe_fallback', $fallbackProbe, ['path' => $fallback]);
            return $fallbackProbe;
        }

        return $probe;
    }

    public function getMyLists()
    {
        $emails = $this->mapper->getCurrentUserEmails();
        $cacheKey = 'my-lists:' . sha1(implode('|', $emails));
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->debug('my_lists.cache_hit', ['emails' => $emails, 'count' => count($cached)]);
            return $cached;
        }

        $this->debug('my_lists.lookup_start', ['emails' => $emails]);
        $lists = [];
        foreach ($emails as $email) {
            foreach ($this->getMembershipsByEmail($email) as $membership) {
                if (empty($membership['list_id']) || !$this->guard->listAllowed($membership['list_id'])) {
                    $this->debug('my_lists.membership_skipped', [
                        'email' => $email,
                        'list_id' => $membership['list_id'] ?? '',
                        'reason' => empty($membership['list_id']) ? 'missing_list_id' : 'list_not_allowed',
                    ]);
                    continue;
                }

                $detail = $this->getListDetail($membership['list_id']);
                if (!$detail) {
                    $this->debug('my_lists.detail_missing', [
                        'email' => $email,
                        'list_id' => $membership['list_id'],
                    ]);
                    continue;
                }

                $detail['membership'] = $membership;
                $lists[$detail['id']] = $detail;
            }
        }

        ksort($lists);
        $result = array_values($lists);
        $this->debug('my_lists.lookup_complete', ['emails' => $emails, 'count' => count($result)]);
        $this->cache->set($cacheKey, $result);

        return $result;
    }

    public function getDirectoryLists()
    {
        $cached = $this->cache->get('directory-lists');
        if ($cached !== null) {
            $this->debug('directory.cache_hit', ['count' => count($cached)]);
            return $cached;
        }

        $response = $this->client->get('/lists');
        $this->debugResponse('directory.fetch', $response);
        if (!$response['success']) {
            return [];
        }

        $entries = $response['data']['entries'] ?? $response['data'] ?? [];
        $lists = [];
        foreach ((array) $entries as $entry) {
            $list = $this->mapper->normalizeList((array) $entry);
            if (!$list['id'] || !$this->guard->listAllowed($list['id'])) {
                continue;
            }
            if (!$list['advertised']) {
                continue;
            }

            $lists[$list['id']] = $list;
        }

        ksort($lists);
        $result = array_values($lists);
        $this->debug('directory.complete', ['count' => count($result)]);
        $this->cache->set('directory-lists', $result);

        return $result;
    }

    public function getListDetail($listId)
    {
        $listId = $this->mapper->normalizeListId($listId);
        $canonicalId = $this->mapper->canonicalListId($listId);
        if (!$canonicalId || !$this->guard->listAllowed($canonicalId)) {
            return null;
        }

        $cacheKey = 'list:' . $canonicalId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->debug('list.cache_hit', ['list_id' => $canonicalId]);
            return $cached;
        }

        $response = $this->client->get('/lists/' . rawurlencode($canonicalId));
        $this->debugResponse('list.fetch', $response, ['list_id' => $canonicalId]);
        if (!$response['success'] && $listId !== $canonicalId) {
            $response = $this->client->get('/lists/' . rawurlencode($listId));
            $this->debugResponse('list.fetch_alt', $response, ['list_id' => $listId]);
        }

        if (!$response['success']) {
            return null;
        }

        $entry = $this->mapper->normalizeList((array) $response['data']);
        if (!$entry['id']) {
            return null;
        }

        $entry['urls'] = [
            'archives' => $this->config['show_archives'] ? $entry['archive_url'] : '',
            'subscribe_mailto' => 'mailto:' . $entry['address'] . '?subject=subscribe',
            'unsubscribe_mailto' => 'mailto:' . $entry['address'] . '?subject=unsubscribe',
        ];
        $entry['recognized'] = true;
        $entry['subscribed'] = $this->isCurrentUserSubscribedTo($entry['id']);
        $entry['actions'] = [
            'subscribe' => $this->guard->canSubscribe() && !$entry['subscribed'],
            'unsubscribe' => $this->guard->canUnsubscribe() && $entry['subscribed'],
        ];

        $this->cache->set($cacheKey, $entry);

        return $entry;
    }

    public function subscribeCurrentUser($listId)
    {
        $listId = $this->mapper->normalizeListId($listId);
        $canonicalId = $this->mapper->canonicalListId($listId);
        if (!$this->guard->listAllowed($canonicalId) || !$this->guard->canSubscribe() || !$this->getHealth()['write_ok']) {
            return ['success' => false, 'message' => 'mailman_subscribe_unavailable'];
        }

        $emails = $this->mapper->getCurrentUserEmails();
        $primary = reset($emails);
        if (!$primary) {
            return ['success' => false, 'message' => 'mailman_no_identity'];
        }

        $response = $this->client->post('/members', [
            'list_id' => $canonicalId,
            'subscriber' => $primary,
            'pre_verified' => true,
            'pre_confirmed' => true,
            'pre_approved' => true,
        ]);
        $this->debugResponse('subscribe.attempt', $response, ['list_id' => $canonicalId, 'subscriber' => $primary]);

        if (!$response['success']) {
            return ['success' => false, 'message' => 'mailman_subscribe_failed'];
        }

        $this->invalidateListCaches($listId);

        return ['success' => true, 'message' => 'mailman_subscribe_success'];
    }

    public function unsubscribeCurrentUser($listId)
    {
        $listId = $this->mapper->normalizeListId($listId);
        $canonicalId = $this->mapper->canonicalListId($listId);
        if (!$this->guard->listAllowed($canonicalId) || !$this->guard->canUnsubscribe() || !$this->getHealth()['write_ok']) {
            return ['success' => false, 'message' => 'mailman_unsubscribe_unavailable'];
        }

        foreach ($this->mapper->getCurrentUserEmails() as $email) {
            foreach ($this->getMembershipsByEmail($email) as $membership) {
                if ($membership['list_id'] !== $canonicalId) {
                    continue;
                }

                $path = '/members/' . rawurlencode($this->extractMemberId($membership['member_id']));
                $response = $this->client->delete($path);
                $this->debugResponse('unsubscribe.attempt', $response, ['list_id' => $canonicalId, 'subscriber' => $email]);
                if ($response['success']) {
                    $this->invalidateListCaches($listId);
                    return ['success' => true, 'message' => 'mailman_unsubscribe_success'];
                }
            }
        }

        return ['success' => false, 'message' => 'mailman_unsubscribe_failed'];
    }

    public function getComposeWidgetData(array $emails)
    {
        $matches = [];
        foreach ($emails as $email) {
            $list = $this->findRecognizedList($email);
            if ($list) {
                $matches[$list['id']] = [
                    'id' => $list['id'],
                    'name' => $list['name'],
                    'address' => $list['address'],
                    'archive_url' => $this->config['show_archives'] ? $list['archive_url'] : '',
                ];
            }
        }

        return [
            'enabled' => (bool) $this->config['compose_detection'],
            'recognized' => array_values($matches),
        ];
    }

    public function buildOutboundHeaders(array $recipientEmails)
    {
        $matches = [];
        foreach ($recipientEmails as $email) {
            $list = $this->findRecognizedList($email);
            if ($list) {
                $matches[$list['id']] = $list;
            }
        }

        if (count($matches) !== 1) {
            return [];
        }

        $list = reset($matches);
        $headers = [
            'List-Id' => $this->sanitizeHeaderValue($list['name'] . ' <' . $list['id'] . '>'),
            'X-BeenThere' => $this->sanitizeHeaderValue($list['address']),
        ];

        if (!empty($this->config['add_list_unsubscribe'])) {
            $headers['List-Unsubscribe'] = $this->sanitizeHeaderValue('<mailto:' . $list['address'] . '?subject=unsubscribe>');
        }

        if (!empty($this->config['show_archives']) && !empty($list['archive_url'])) {
            $headers['List-Archive'] = $this->sanitizeHeaderValue('<' . $list['archive_url'] . '>');
        }

        return $headers;
    }

    public function findRecognizedList($email)
    {
        $email = $this->mapper->normalizeEmail($email);
        if ($email === '' || !$this->guard->listAllowed($email)) {
            return null;
        }

        return $this->getListDetail($email);
    }

    private function getMembershipsByEmail($email)
    {
        $cacheKey = 'memberships:' . sha1($email);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $this->debug('memberships.cache_hit', ['email' => $email, 'count' => count($cached)]);
            return $cached;
        }

        $response = $this->client->get('/members/find', ['subscriber' => $email]);
        $this->debugResponse('memberships.fetch', $response, ['email' => $email]);
        if (!$response['success']) {
            return [];
        }

        $entries = $response['data']['entries'] ?? $response['data'] ?? [];
        $memberships = [];
        foreach ((array) $entries as $entry) {
            $memberships[] = $this->mapper->normalizeMember((array) $entry);
        }

        $this->debug('memberships.complete', ['email' => $email, 'count' => count($memberships)]);
        $this->cache->set($cacheKey, $memberships);

        return $memberships;
    }

    private function isCurrentUserSubscribedTo($listId)
    {
        foreach ($this->mapper->getCurrentUserEmails() as $email) {
            foreach ($this->getMembershipsByEmail($email) as $membership) {
                if ($membership['list_id'] === $listId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractMemberId($memberId)
    {
        if (strpos($memberId, '/members/') !== false) {
            return basename(parse_url($memberId, PHP_URL_PATH));
        }

        return $memberId;
    }

    private function invalidateListCaches($listId)
    {
        $this->cache->delete('health');
        $this->cache->delete('directory-lists');
        $this->cache->delete('list:' . $this->mapper->normalizeListId($listId));
        $this->cache->delete('my-lists:' . sha1(implode('|', $this->mapper->getCurrentUserEmails())));
    }

    private function isDebugEnabled()
    {
        return !empty($this->config['debug']);
    }

    private function debug($event, array $context = [])
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $entry = [
            'event' => (string) $event,
            'context' => $this->sanitizeDebugValue($context),
        ];

        $this->debugEntries[] = $entry;
        rcube::write_log('mailman_integration', '[' . $entry['event'] . '] ' . json_encode($entry['context']));
    }

    private function debugResponse($event, array $response, array $context = [])
    {
        $payload = $context + [
            'success' => !empty($response['success']),
            'status' => (int) ($response['status'] ?? 0),
            'request' => $response['request'] ?? [],
            'error' => $response['error'] ?? null,
            'data_summary' => $this->summarizeResponseData($response['data'] ?? null),
        ];

        $this->debug($event, $payload);
    }

    private function summarizeResponseData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $summary = [
            'keys' => array_slice(array_keys($data), 0, 12),
        ];

        if (isset($data['entries']) && is_array($data['entries'])) {
            $summary['entries_count'] = count($data['entries']);
        }

        if (isset($data['self_link'])) {
            $summary['self_link'] = (string) $data['self_link'];
        }

        if (isset($data['fqdn_listname'])) {
            $summary['fqdn_listname'] = (string) $data['fqdn_listname'];
        }

        if (isset($data['description'])) {
            $summary['description'] = (string) $data['description'];
        }

        if (isset($data['title'])) {
            $summary['title'] = (string) $data['title'];
        }

        return $summary;
    }

    private function sanitizeDebugValue($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (in_array((string) $key, ['api_password', 'password', 'authorization'], true)) {
                    $result[$key] = '[redacted]';
                    continue;
                }

                $result[$key] = $this->sanitizeDebugValue($item);
            }

            return $result;
        }

        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }

        return $value;
    }

    private function sanitizeHeaderValue($value)
    {
        $value = (string) $value;

        return str_replace(["\r", "\n"], ' ', $value);
    }
}
