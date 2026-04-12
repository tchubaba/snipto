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
     * @param Policy $policy The policy instance to be configured.
     *
     * @return void
     */
    public function configure(Policy $policy): void
    {
        parent::configure($policy);

        $policy
            ->add(Directive::DEFAULT, Keyword::NONE)
            ->add(Directive::CONNECT, [Keyword::SELF, config('app.url')])
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::MANIFEST, Keyword::SELF)
            ->add(Directive::REQUIRE_TRUSTED_TYPES_FOR, Keyword::SCRIPT);
    }
}
