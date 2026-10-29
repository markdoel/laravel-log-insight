<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TestHeaderMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Log-Insight-Test', 'yes');

        return $response;
    }
}
