<?php
namespace RRZE\WebT;

defined('ABSPATH') || exit;

class JobStore {
    private const INDEX_META_KEY = '_rrze_webt_jobs_index';
    private const JOB_META_PREFIX = '_rrze_webt_job_';
    private const REQUEST_META_PREFIX = '_rrze_webt_job_request_';

    public function create_job( array $job ): void {
        $post_id = (int) ( $job['post_id'] ?? 0 );

        if ( $post_id <= 0 ) {
            error_log( sprintf( 'RRZE WEB-T: skipped job creation for token=%s because post_id is invalid', $job['token'] ?? '' ) );
            return;
        }

        $token      = (string) ( $job['token'] ?? '' );
        $request_id = (string) ( $job['request_id'] ?? '' );

        if ( '' === $token || '' === $request_id ) {
            error_log( 'RRZE WEB-T: skipped job creation due to missing token or request id.' );
            return;
        }

        $defaults = [
            'post_id'         => $post_id,
            'token'           => $token,
            'request_id'      => $request_id,
            'target_language' => '',
            'source_language' => 'AUTO',
            'status'          => 'pending',
            'submitted_at'    => time(),
            'updated_at'      => time(),
            'content'         => '',
            'title'           => '',
            'message'         => __( 'Waiting for WEB-T to process this translation request.', 'rrze-webt' ),
            'acknowledged'    => false,
            'acknowledged_at' => 0,
        ];

        $job = array_merge( $defaults, $job );

        update_post_meta( $post_id, $this->job_meta_key( $token ), $job );
        update_post_meta( $post_id, $this->request_meta_key( $request_id ), $token );

        $index = $this->get_jobs_index( $post_id );
        $index = array_values( array_filter( $index, static function ( $existing ) use ( $token ) {
            return $existing !== $token;
        } ) );
        array_unshift( $index, $token );

        $this->trim_and_store_index( $post_id, $index );
        error_log( sprintf( 'RRZE WEB-T: create job token=%s request=%s post=%d', $token, $request_id, $post_id ) );
    }

    public function update_job_status( string $token, array $changes ): ?array {
        $job = $this->get_job_by_token( $token );

        if ( ! $job ) {
            return null;
        }

        $post_id = (int) $job['post_id'];

        $job = array_merge( $job, $changes );
        $job['updated_at'] = time();

        update_post_meta( $post_id, $this->job_meta_key( $token ), $job );

        $index = $this->get_jobs_index( $post_id );
        $index = array_values( array_filter( $index, static function ( $existing ) use ( $token ) {
            return $existing !== $token;
        } ) );
        array_unshift( $index, $token );
        $this->trim_and_store_index( $post_id, $index );

        return $job;
    }

    public function complete_job( string $token, array $data ): ?array {
        return $this->update_job_status(
            $token,
            [
                'status'  => 'completed',
                'content' => $data['content'] ?? '',
                'title'   => $data['title'] ?? '',
                'message' => __( 'Translation received from WEB-T.', 'rrze-webt' ),
            ]
        );
    }

    public function fail_job( string $token, string $message ): ?array {
        return $this->update_job_status(
            $token,
            [
                'status'  => 'failed',
                'message' => $message,
            ]
        );
    }

    public function acknowledge_job( string $token ): ?array {
        return $this->update_job_status(
            $token,
            [
                'acknowledged'    => true,
                'acknowledged_at' => time(),
            ]
        );
    }

    public function get_job_by_token( string $token ): ?array {
        $post_id = $this->find_post_id_by_meta_key( $this->job_meta_key( $token ) );

        if ( ! $post_id ) {
            return null;
        }

        $job = get_post_meta( $post_id, $this->job_meta_key( $token ), true );

        return is_array( $job ) ? $job : null;
    }

    public function get_job_by_request_id( string $request_id ): ?array {
        $post_id = $this->find_post_id_by_meta_key( $this->request_meta_key( $request_id ) );

        if ( ! $post_id ) {
            return null;
        }

        $token = get_post_meta( $post_id, $this->request_meta_key( $request_id ), true );

        if ( ! $token ) {
            return null;
        }

        return $this->get_job_by_token( (string) $token );
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function get_jobs_for_post( int $post_id ): array {
        $tokens = $this->get_jobs_index( $post_id );
        $jobs   = [];

        foreach ( $tokens as $token ) {
            $job = get_post_meta( $post_id, $this->job_meta_key( $token ), true );

            if ( is_array( $job ) ) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function remove_job( string $token ): bool {
        $job = $this->get_job_by_token( $token );

        if ( ! $job ) {
            return false;
        }

        $post_id    = (int) $job['post_id'];
        $request_id = (string) ( $job['request_id'] ?? '' );

        delete_post_meta( $post_id, $this->job_meta_key( $token ) );

        if ( $request_id ) {
            delete_post_meta( $post_id, $this->request_meta_key( $request_id ) );
        }

        $index = $this->get_jobs_index( $post_id );
        $index = array_values( array_filter( $index, static function ( $existing ) use ( $token ) {
            return $existing !== $token;
        } ) );

        $this->save_jobs_index( $post_id, $index );

        error_log( sprintf( 'RRZE WEB-T: remove job token=%s for post=%d', $token, $post_id ) );

        return true;
    }

    private function trim_and_store_index( int $post_id, array $tokens ): void {
        $tokens = array_values( $tokens );

        while ( count( $tokens ) > 3 ) {
            $token_to_remove = array_pop( $tokens );
            $job             = get_post_meta( $post_id, $this->job_meta_key( $token_to_remove ), true );

            if ( is_array( $job ) ) {
                $request_id = (string) ( $job['request_id'] ?? '' );

                if ( $request_id ) {
                    delete_post_meta( $post_id, $this->request_meta_key( $request_id ) );
                }
            }

            delete_post_meta( $post_id, $this->job_meta_key( $token_to_remove ) );
        }

        $this->save_jobs_index( $post_id, $tokens );

        error_log( sprintf( 'RRZE WEB-T: enforce limit for post %d tokens kept: %s', $post_id, wp_json_encode( $tokens ) ) );
    }

    private function get_jobs_index( int $post_id ): array {
        $tokens = get_post_meta( $post_id, self::INDEX_META_KEY, true );
        return is_array( $tokens ) ? $tokens : [];
    }

    private function save_jobs_index( int $post_id, array $tokens ): void {
        if ( empty( $tokens ) ) {
            delete_post_meta( $post_id, self::INDEX_META_KEY );
            return;
        }

        update_post_meta( $post_id, self::INDEX_META_KEY, array_values( $tokens ) );
        update_post_meta( $post_id, '_rrze_webt_last_target_language', $tokens[0] ? ( $this->get_job_by_token( $tokens[0] )['target_language'] ?? '' ) : '' );
        update_post_meta( $post_id, '_rrze_webt_last_source_language', $tokens[0] ? ( $this->get_job_by_token( $tokens[0] )['source_language'] ?? '' ) : '' );
    }

    private function find_post_id_by_meta_key( string $meta_key ): ?int {
        $posts = get_posts(
            [
                'post_type'      => 'any',
                'post_status'    => 'any',
                'numberposts'    => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => $meta_key,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]
        );

        if ( empty( $posts ) ) {
            return null;
        }

        return (int) $posts[0];
    }

    private function job_meta_key( string $token ): string {
        return self::JOB_META_PREFIX . $token;
    }

    private function request_meta_key( string $request_id ): string {
        return self::REQUEST_META_PREFIX . $request_id;
    }
}
