<?php
/**
 * Fictitious example used ONLY to exercise the public-changelog release flow end
 * to end (version bump → CHANGELOG.md → readme.txt → changelog/{version}.json →
 * gate → WordPress.org + docs.y.uno publish).
 *
 * It is intentionally isolated and not wired into the plugin (never included or
 * called), so it is safe to remove once the flow has been validated.
 *
 * @package Yuno_Payment_Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns a trivial, deterministic payload. Stands in for "a real change worth
 * releasing" so we have something concrete to document in the changelog.
 *
 * @return array{ok: bool, message: string}
 */
function yuno_release_flow_demo() {
    return array(
        'ok'      => true,
        'message' => 'sdk-woocommerce release-flow demo',
    );
}
