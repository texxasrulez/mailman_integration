<?php

class MailmanGuard
{
    private $rc;
    private $config;
    private $mapper;

    public function __construct(rcube $rc, array $config, MailmanMapper $mapper)
    {
        $this->rc = $rc;
        $this->config = $config;
        $this->mapper = $mapper;
    }

    public function canBrowseDirectory()
    {
        return (bool) $this->config['show_directory'];
    }

    public function canSubscribe()
    {
        return (bool) $this->config['allow_subscribe'];
    }

    public function canUnsubscribe()
    {
        return (bool) $this->config['allow_unsubscribe'];
    }

    public function listAllowed($listId)
    {
        $listId = $this->mapper->normalizeListId($listId);
        $alternate = $this->mapper->listIdToAddress($listId);
        if ($alternate !== '') {
            $alternate = $this->mapper->normalizeListId($alternate);
        }
        $candidates = array_values(array_unique(array_filter([$listId, $alternate])));
        $allowed = array_map([$this->mapper, 'normalizeListId'], (array) $this->config['allowed_lists']);
        $blocked = array_map([$this->mapper, 'normalizeListId'], (array) $this->config['blocked_lists']);

        if (!empty($allowed)) {
            $matched = false;
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $allowed, true)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $blocked, true)) {
                return false;
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->domainAllowed($candidate)) {
                return true;
            }
        }

        return empty($candidates);
    }

    public function domainAllowed($listId)
    {
        $domains = array_filter(array_map('strtolower', (array) $this->config['exposed_domains']));
        if (empty($domains)) {
            return true;
        }

        $parts = explode('@', $listId);
        if (count($parts) !== 2) {
            return false;
        }

        return in_array(strtolower($parts[1]), $domains, true);
    }
}
