<?php

namespace Tests\Unit\Support;

use App\Support\HttpProxy;
use Tests\TestCase;

class HttpProxyTest extends TestCase
{
    public function test_returns_empty_options_when_no_proxy_configured(): void
    {
        config(['archives.curl_proxy' => null]);

        $this->assertSame([], HttpProxy::options());
    }

    public function test_returns_proxy_option_when_proxy_configured(): void
    {
        config(['archives.curl_proxy' => 'http://proxy.test:3128']);

        $this->assertSame(['proxy' => 'http://proxy.test:3128'], HttpProxy::options());
    }
}
