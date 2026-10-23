<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Presets\Basic;

class SniptoPolicy extends Basic
{
    /**
     * Configures the given policy with predefined security directives
     * to control resource loading and enforce stricter content security.
     *
     * @param  Policy  $policy  The policy instance to be configured.
     */
    public function configure(Policy $policy): void
    {
        parent::configure($policy);

        $policy
            ->add(Directive::DEFAULT, Keyword::NONE)
            ->add(Directive::CONNECT, [Keyword::SELF, config('app.url')])
            // No framed content of any kind: snippet rendering happens in
            // sandboxed srcdoc iframes, which are exempt from frame-src (no
            // URL fetch occurs). 'none' therefore blocks only attacker-
            // injected external frames (audit finding F-5).
            ->add(Directive::FRAME, Keyword::NONE)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::MANIFEST, Keyword::SELF)
            ->add(Directive::REQUIRE_TRUSTED_TYPES_FOR, Keyword::SCRIPT)
            ->add(Directive::SCRIPT, Keyword::UNSAFE_WEB_ASSEMBLY_EXECUTION);
    }
}
