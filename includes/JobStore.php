<?php

namespace RRZE\WebT;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Persist translation jobs and serialize their state changes per post.
 *
 * Named MySQL/MariaDB locks protect short read/modify/write sections across PHP
 * workers. No lock may be held while calling the translation provider.
 *
 * @package RRZE\WebT
 */
class JobStore
{
    /** @var string Ordered list of job tokens belonging to a post. */
    private const INDEX_META_KEY = '_rrze_webt_jobs_index';

    /** @var string Metadata prefix for job records. */
    private const JOB_META_PREFIX = '_rrze_webt_job_';

    /** @var string Metadata prefix for provider-ID lookups. */
    private const REQUEST_META_PREFIX = '_rrze_webt_job_request_';

    /** @var array<string,bool> Reentrant locks already held by this PHP request. */
    private static array $locks = [];

    /**
     * Run a short operation exclusively for one site's post, without waiting.
     *
     * The connection releases the DB lock even if a worker terminates. Failure
     * to acquire a lock must fail closed; no unlocked fallback is safe here.
     *
     * @param int      $post_id Post whose job state is accessed.
     * @param callable $operation Operation to run while holding the lock.
     * @return mixed Operation result, or WP_Error when the lock is unavailable.
     */
    public function with_post_lock(int $post_id, callable $operation)
    {
        global $wpdb;
        $name = 'rrze-webt:' . sha1(DB_NAME . ':' . $wpdb->postmeta . ':' . $post_id);
        if (isset(self::$locks[$name])) {
            return $operation();
        }
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $acquired !== '1') {
            return new WP_Error('rrze_webt_job_busy', __('Translation jobs are being updated. Please try again shortly.', 'rrze-webt'), ['status' => 503]);
        }
        self::$locks[$name] = true;
        // Another worker may have changed metadata since this request first read it.
        wp_cache_delete($post_id, 'post_meta');
        try {
            return $operation();
        } finally {
            unset(self::$locks[$name]);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * Reserve a local job, reuse an identical pending one, or reject competing input.
     *
     * @param array<string,mixed> $job New job including its request fingerprint.
     * @return array{job:array,created:bool}|WP_Error Reservation or storage/conflict error.
     */
    public function prepare_job(array $job)
    {
        $post_id = (int) $job['post_id'];
        return $this->with_post_lock($post_id,
            /** @return array|WP_Error Reservation made under the post lock. */
            function () use ($job, $post_id) {
                $expired = $this->expire_jobs($post_id);
                if (is_wp_error($expired)) {
                    return $expired;
                }
                foreach ($this->get_jobs_for_post($post_id) as $existing) {
                    if ($existing['status'] !== 'pending') {
                        continue;
                    }
                    if (($existing['fingerprint'] ?? '') === $job['fingerprint']) {
                        return ['job' => $existing, 'created' => false];
                    }
                    return new WP_Error('rrze_webt_job_pending', __('A translation for this post is still pending. Wait for it to finish before submitting different content or languages.', 'rrze-webt'), ['status' => 409, 'token' => $existing['token']]);
                }
                $created = $this->create_job($job);
                return is_wp_error($created) ? $created : ['job' => $created, 'created' => true];
            }
        );
    }

    /**
     * Persist a job before dispatch; its provider request ID may still be unknown.
     *
     * @param array<string,mixed> $job Must include an existing post_id and a token.
     * @return array<string,mixed>|WP_Error Stored record or persistence error.
     */
    public function create_job(array $job)
    {
        $post_id = (int) ($job['post_id'] ?? 0);
        if ($post_id <= 0 || empty($job['token']) || ! get_post($post_id)) {
            return $this->storage_error();
        }
        return $this->with_post_lock($post_id,
            /** @return array|WP_Error Stored job created under the post lock. */
            function () use ($job, $post_id) {
                $token = (string) $job['token'];
                $request_id = (string) ($job['request_id'] ?? '');
                $defaults = [
                    'post_id' => $post_id, 'token' => $token, 'request_id' => $request_id,
                    'target_language' => '', 'source_language' => 'AUTO',
                    'status' => 'pending', 'submission_state' => $request_id === '' ? 'submitting' : 'accepted',
                    'submitted_at' => time(), 'updated_at' => time(),
                    'content' => '', 'title' => '', 'error_code' => '',
                    'message' => __('Waiting for WEB-T to process this translation request.', 'rrze-webt'),
                    'acknowledged' => false, 'acknowledged_at' => 0,
                ];
                $job = array_merge($defaults, $job);
                if (! add_post_meta($post_id, $this->job_meta_key($token), wp_slash($job), true)) {
                    return $this->storage_error();
                }
                if ($request_id !== '') {
                    update_post_meta($post_id, $this->request_meta_key($request_id), $token);
                }
                $tokens = $this->get_jobs_index($post_id);
                array_unshift($tokens, $token);
                if (! $this->trim_and_store_index($post_id, $tokens)) {
                    delete_post_meta($post_id, $this->job_meta_key($token));
                    if ($request_id !== '') {
                        delete_post_meta($post_id, $this->request_meta_key($request_id));
                    }
                    return $this->storage_error();
                }
                return $job;
            }
        );
    }

    /**
     * Change metadata without resetting terminal states or replacing a bound ID.
     *
     * @param string              $token Local job token.
     * @param array<string,mixed> $changes New field values.
     * @return array<string,mixed>|WP_Error|null Updated job, error, or missing record.
     */
    public function update_job_status(string $token, array $changes)
    {
        $post_id = $this->find_post_id_by_meta_key($this->job_meta_key($token));
        if (! $post_id) {
            return null;
        }
        return $this->with_post_lock($post_id,
            /** @return array|WP_Error|null Updated job read freshly under the lock. */
            function () use ($token, $changes, $post_id) {
                $job = $this->get_job_by_token($token);
                if (! $job) {
                    return null;
                }
                unset($changes['token'], $changes['post_id'], $changes['fingerprint'], $changes['api_version']);
                if (isset($changes['request_id']) && $job['request_id'] !== ''
                    && (string) $changes['request_id'] !== (string) $job['request_id']) {
                    return new WP_Error('rrze_webt_request_id_conflict', __('The service returned conflicting translation request IDs.', 'rrze-webt'), ['status' => 502]);
                }
                if ($job['status'] !== 'pending' && isset($changes['status']) && $changes['status'] !== $job['status']) {
                    return $job;
                }
                $next = array_merge($job, $changes);
                if ($next === $job) {
                    return $job;
                }
                $next['updated_at'] = time();
                if (! update_post_meta($post_id, $this->job_meta_key($token), wp_slash($next))
                    && get_post_meta($post_id, $this->job_meta_key($token), true) !== $next) {
                    return $this->storage_error();
                }
                if ($next['request_id'] !== '') {
                    update_post_meta($post_id, $this->request_meta_key($next['request_id']), $token);
                }
                if (! $this->trim_and_store_index($post_id, $this->get_jobs_index($post_id))) {
                    return $this->storage_error();
                }
                return $next;
            }
        );
    }

    /**
     * Transition a pending job once; replays never receive a writable result.
     *
     * @param string              $token Local job token.
     * @param array<string,mixed> $changes Terminal status and accompanying fields.
     * @return array<string,mixed>|WP_Error|null Newly changed job, error, or no transition.
     */
    private function transition_job(string $token, array $changes)
    {
        $job = $this->get_job_by_token($token);
        if (! $job) {
            return null;
        }
        return $this->with_post_lock((int) $job['post_id'],
            /** @return array|WP_Error|null Result of the single pending-to-terminal transition. */
            function () use ($token, $changes) {
                $current = $this->get_job_by_token($token);
                if (! $current || $current['status'] !== 'pending') {
                    return null;
                }
                return $this->update_job_status($token, $changes);
            }
        );
    }

    /**
     * Store a translated document exactly once for a pending job.
     *
     * @param string              $token Local job token.
     * @param array<string,mixed> $data Translated title and content.
     * @return array<string,mixed>|WP_Error|null Newly completed job, error, or replay.
     */
    public function complete_job(string $token, array $data)
    {
        return $this->transition_job($token, [
            'status' => 'completed', 'content' => $data['content'] ?? '', 'title' => $data['title'] ?? '',
            'submission_state' => 'accepted', 'error_code' => '',
            'message' => __('Translation received from WEB-T.', 'rrze-webt'),
        ]);
    }

    /**
     * Record a definitive failure without overwriting a completed or failed job.
     *
     * @param string $token Local job token.
     * @param string $message Human-readable failure reason.
     * @param string $error_code Stable diagnostic code, without credentials or content.
     * @return array<string,mixed>|WP_Error|null Newly failed job, error, or replay.
     */
    public function fail_job(string $token, string $message, string $error_code = '')
    {
        return $this->transition_job($token, ['status' => 'failed', 'message' => $message, 'error_code' => $error_code]);
    }

    /**
     * Expire pending jobs consistently during reads, submissions and callbacks.
     *
     * @param int $post_id Post whose waiting jobs should be checked.
     * @return true|WP_Error Success or storage/lock failure.
     */
    public function expire_jobs(int $post_id)
    {
        return $this->with_post_lock($post_id,
            /** @return true|WP_Error Result of expiration under the post lock. */
            function () use ($post_id) {
                $max_age = max(1, (int) apply_filters('rrze_webt_job_max_age_seconds', 5 * 60));
                foreach ($this->get_jobs_for_post($post_id) as $job) {
                    $deadline = (int) ($job['expires_at'] ?? ((int) $job['submitted_at'] + $max_age));
                    if ($job['status'] === 'pending' && $deadline <= time()) {
                        $result = $this->fail_job($job['token'], __('Timed out waiting for WEB-T response.', 'rrze-webt'), 'rrze_webt_job_timeout');
                        if (is_wp_error($result)) {
                            return $result;
                        }
                    }
                }
                return true;
            }
        );
    }

    /**
     * Mark a job as seen without changing its position in the history.
     *
     * @param string $token Local job token.
     * @return array<string,mixed>|WP_Error|null Updated job, error, or missing record.
     */
    public function acknowledge_job(string $token)
    {
        return $this->update_job_status($token, ['acknowledged' => true, 'acknowledged_at' => time()]);
    }

    /**
     * Get a persisted job by its local token.
     *
     * @param string $token Local job token.
     * @return array<string,mixed>|null Job or null when absent.
     */
    public function get_job_by_token(string $token): ?array
    {
        $post_id = $this->find_post_id_by_meta_key($this->job_meta_key($token));
        $job = $post_id ? get_post_meta($post_id, $this->job_meta_key($token), true) : null;
        return is_array($job) ? $job : null;
    }

    /**
     * Resolve a provider request ID to its local job.
     *
     * @param string $request_id Provider request ID.
     * @return array<string,mixed>|null Job or null when absent.
     */
    public function get_job_by_request_id(string $request_id): ?array
    {
        if ($request_id === '') {
            return null;
        }
        $post_id = $this->find_post_id_by_meta_key($this->request_meta_key($request_id));
        $token = $post_id ? get_post_meta($post_id, $this->request_meta_key($request_id), true) : '';
        return $token ? $this->get_job_by_token((string) $token) : null;
    }

    /**
     * Get jobs in submission order, newest first.
     *
     * @param int $post_id Owning post ID.
     * @return array<int,array<string,mixed>> Active jobs and retained history.
     */
    public function get_jobs_for_post(int $post_id): array
    {
        $jobs = [];
        foreach ($this->get_jobs_index($post_id) as $token) {
            $job = get_post_meta($post_id, $this->job_meta_key($token), true);
            if (is_array($job)) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    /**
     * Remove a job and its ID lookup while preserving the other records.
     *
     * @param string $token Local job token.
     * @return bool|WP_Error Whether a job was removed, or a lock/storage error.
     */
    public function remove_job(string $token)
    {
        $job = $this->get_job_by_token($token);
        if (! $job) {
            return false;
        }
        return $this->with_post_lock((int) $job['post_id'],
            /** @return bool|WP_Error Removal result under the post lock. */
            function () use ($token) {
                $job = $this->get_job_by_token($token);
                if (! $job) {
                    return false;
                }
                $post_id = (int) $job['post_id'];
                $tokens = array_values(array_diff($this->get_jobs_index($post_id), [$token]));
                if (! $this->save_jobs_index($post_id, $tokens)) {
                    return $this->storage_error();
                }
                delete_post_meta($post_id, $this->job_meta_key($token));
                if ($job['request_id'] !== '') {
                    delete_post_meta($post_id, $this->request_meta_key($job['request_id']));
                }
                return true;
            }
        );
    }

    /**
     * Keep every active job and the three newest terminal jobs; caller holds lock.
     *
     * @param int      $post_id Owning post ID.
     * @param string[] $tokens Tokens in submission order.
     * @return bool Whether the index was stored successfully.
     */
    private function trim_and_store_index(int $post_id, array $tokens): bool
    {
        $keep = [];
        $remove = [];
        $finished = 0;
        foreach (array_unique($tokens) as $token) {
            $job = get_post_meta($post_id, $this->job_meta_key($token), true);
            if (! is_array($job)) {
                continue;
            }
            if ($job['status'] === 'pending' || ++$finished <= 3) {
                $keep[] = $token;
            } else {
                $remove[] = $job;
            }
        }
        if (! $this->save_jobs_index($post_id, $keep)) {
            return false;
        }
        foreach ($remove as $job) {
            delete_post_meta($post_id, $this->job_meta_key($job['token']));
            if ($job['request_id'] !== '') {
                delete_post_meta($post_id, $this->request_meta_key($job['request_id']));
            }
        }
        return true;
    }

    /**
     * Read the ordered token index.
     *
     * @param int $post_id Owning post ID.
     * @return string[] Indexed job tokens.
     */
    private function get_jobs_index(int $post_id): array
    {
        $tokens = get_post_meta($post_id, self::INDEX_META_KEY, true);
        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Store index and last-language hints; caller holds the post lock.
     *
     * @param int      $post_id Owning post ID.
     * @param string[] $tokens Ordered job tokens.
     * @return bool Whether the index equals the requested state.
     */
    private function save_jobs_index(int $post_id, array $tokens): bool
    {
        $tokens = array_values($tokens);
        update_post_meta($post_id, self::INDEX_META_KEY, $tokens);
        if (get_post_meta($post_id, self::INDEX_META_KEY, true) !== $tokens) {
            return false;
        }
        $latest = $tokens ? get_post_meta($post_id, $this->job_meta_key($tokens[0]), true) : [];
        update_post_meta($post_id, '_rrze_webt_last_target_language', $latest['target_language'] ?? '');
        update_post_meta($post_id, '_rrze_webt_last_source_language', $latest['source_language'] ?? '');
        return true;
    }

    /**
     * Find the owner directly so cached WP_Query results cannot hide a new job.
     *
     * @param string $meta_key Exact job or provider-ID metadata key.
     * @return int|null Owning post ID or null.
     */
    private function find_post_id_by_meta_key(string $meta_key): ?int
    {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $meta_key));
        return $id ? (int) $id : null;
    }

    /**
     * Build the metadata key for a local job.
     *
     * @param string $token Local job token.
     * @return string Metadata key.
     */
    private function job_meta_key(string $token): string
    {
        return self::JOB_META_PREFIX . $token;
    }

    /**
     * Build the metadata key for a provider-ID lookup.
     *
     * @param string $request_id Provider request ID.
     * @return string Metadata key.
     */
    private function request_meta_key(string $request_id): string
    {
        return self::REQUEST_META_PREFIX . $request_id;
    }

    /**
     * Create a storage error that stops dispatch or asks a callback to retry.
     *
     * @return WP_Error Retryable storage failure.
     */
    private function storage_error(): WP_Error
    {
        return new WP_Error('rrze_webt_job_storage', __('The translation job could not be saved. Please try again shortly.', 'rrze-webt'), ['status' => 503]);
    }
}
