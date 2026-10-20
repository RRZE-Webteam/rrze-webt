<?php
/**
 * Explicit repair backlog, not successful test expectations.
 *
 * Each case still checks the desired behavior. Only a false final expectation
 * may be classified as KNOWN; setup errors and exceptions always fail. An
 * unexpected pass also fails, requiring removal of the resolved entry here.
 *
 * @package RRZE\WebT\Tests
 */
return [
    'client_v2' => 'Step 2: the client still changes the endpoint and uses the v1 protocol.',
    'delivery_v2' => 'Step 2: the callback parser does not extract the v2 result field.',
    'early_callback' => 'Step 3: the local job is created after the remote request returns.',
    'duplicate_callback' => 'Step 3: replaying a delivery writes the post again.',
    'repeated_submit' => 'Step 3: repeated submissions create separate active jobs.',
    'trace_timeout' => 'Step 3: a transport failure leaves no local job record.',
    'unknown_payload' => 'Steps 2/4: unknown JSON is accepted as translated content.',
    'callback_correlation' => 'Step 6: callback request IDs and target languages are not checked.',
    'acknowledge_permission' => 'Step 6: acknowledging a job does not require edit permission.',
    'preserve_post' => 'Step 4: successful callbacks overwrite the stored post automatically.',
];
