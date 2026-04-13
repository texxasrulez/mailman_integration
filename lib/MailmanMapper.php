<?php

class MailmanMapper
{
    private $rc;
    private $config;

    public function __construct(rcube $rc, array $config)
    {
        $this->rc = $rc;
        $this->config = $config;
    }

    public function getCurrentUserEmails()
    {
        $emails = [];
        $user = $this->rc->user;

        if ($user && $user->get_username()) {
            $emails[] = $this->normalizeEmail($user->get_username());
        }

        if ($user && method_exists($user, 'list_identities')) {
            foreach ((array) $user->list_identities() as $identity) {
                if (!empty($identity['email'])) {
                    $emails[] = $this->normalizeEmail($identity['email']);
                }
            }
        }

        $emails = array_values(array_unique(array_filter($emails)));

        if (empty($this->config['allow_identity_aliases']) && !empty($emails)) {
            return [reset($emails)];
        }

        return $emails;
    }

    public function normalizeEmail($email)
    {
        return strtolower(trim((string) $email));
    }

    public function normalizeListId($listId)
    {
        return strtolower(trim((string) $listId));
    }

    public function canonicalListId($listId)
    {
        $listId = $this->normalizeListId($listId);
        if (strpos($listId, '@') !== false) {
            return str_replace('@', '.', $listId);
        }

        return $listId;
    }

    public function listIdToAddress($listId)
    {
        $listId = $this->normalizeListId($listId);
        if ($listId === '') {
            return '';
        }

        if (strpos($listId, '@') !== false) {
            return $listId;
        }

        if (strpos($listId, '.') !== false) {
            return preg_replace('/\./', '@', $listId, 1);
        }

        return $listId;
    }

    public function normalizeList(array $list)
    {
        $listIdRaw = $list['list_id'] ?? '';
        $fqdn = $list['fqdn_listname'] ?? '';
        if ($fqdn === '' && $listIdRaw !== '') {
            $fqdn = $this->listIdToAddress($listIdRaw);
        }
        if ($fqdn === '' && !empty($list['list_name']) && !empty($list['mail_host'])) {
            $fqdn = $list['list_name'] . '@' . $list['mail_host'];
        }
        if ($listIdRaw === '' && $fqdn !== '') {
            $listIdRaw = $this->canonicalListId($fqdn);
        }
        $canonicalId = $this->canonicalListId($listIdRaw ?: $fqdn);
        $display = $list['display_name'] ?? $list['fqdn_listname'] ?? $list['list_name'] ?? '';
        $address = $list['posting_address'] ?? $list['fqdn_listname'] ?? '';
        if ($address === '') {
            $address = $fqdn;
        }

        return [
            'id' => $canonicalId,
            'name' => (string) $display,
            'address' => $this->normalizeEmail($address),
            'fqdn_listname' => $this->normalizeListId($fqdn),
            'list_id' => $canonicalId,
            'description' => (string) ($list['description'] ?? ''),
            'archive_url' => $this->sanitizeHttpUrl((string) ($list['archive_url'] ?? '')),
            'created_at' => (string) ($list['created_at'] ?? ''),
            'mail_host' => (string) ($list['mail_host'] ?? ''),
            'list_name' => (string) ($list['list_name'] ?? ''),
            'advertised' => array_key_exists('advertised', $list) ? !empty($list['advertised']) : true,
        ];
    }

    public function normalizeMember(array $member)
    {
        $subscriber = $member['email'] ?? $member['subscriber'] ?? $member['address'] ?? '';
        $listId = $member['list_id'] ?? $member['fqdn_listname'] ?? '';
        if (is_array($member['list'] ?? null)) {
            $listId = $member['list']['fqdn_listname'] ?? $listId;
        }

        return [
            'member_id' => (string) ($member['member_id'] ?? $member['self_link'] ?? ''),
            'subscriber' => $this->normalizeEmail($subscriber),
            'display_name' => (string) ($member['display_name'] ?? ''),
            'role' => (string) ($member['role'] ?? 'member'),
            'delivery_mode' => (string) ($member['delivery_mode'] ?? ''),
            'list_id' => $this->canonicalListId($listId),
        ];
    }

    private function sanitizeHttpUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }
}
