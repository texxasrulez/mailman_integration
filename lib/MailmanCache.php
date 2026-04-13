<?php

class MailmanCache
{
    private $rc;
    private $ttl;
    private $memory = [];

    public function __construct(rcube $rc, $ttl)
    {
        $this->rc = $rc;
        $this->ttl = max(0, (int) $ttl);
    }

    public function get($key)
    {
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }

        if ($this->ttl < 1 || empty($this->rc->cache) || !method_exists($this->rc->cache, 'get')) {
            return null;
        }

        $value = $this->rc->cache->get('mailman_integration:' . $key);
        if ($value !== null) {
            $this->memory[$key] = $value;
        }

        return $value;
    }

    public function set($key, $value)
    {
        $this->memory[$key] = $value;

        if ($this->ttl > 0 && !empty($this->rc->cache) && method_exists($this->rc->cache, 'set')) {
            $this->rc->cache->set('mailman_integration:' . $key, $value, $this->ttl);
        }
    }

    public function delete($key)
    {
        unset($this->memory[$key]);

        if (!empty($this->rc->cache) && method_exists($this->rc->cache, 'remove')) {
            $this->rc->cache->remove('mailman_integration:' . $key);
        }
    }
}
