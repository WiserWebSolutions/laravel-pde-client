<?php

namespace WiserWebSolutions\PDEClient\Tests\Finders;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use WiserWebSolutions\PDEClient\Enrollment\Finders\EnrollmentFileFinder;
use WiserWebSolutions\PDEClient\Exceptions\PageFetchException;
use WiserWebSolutions\PDEClient\Tests\TestCase;

/**
 * Exercises AbstractHtmlFinder's own shared plumbing (fetch/parse, chrome
 * exclusion, failure translation, caching) through a real concrete
 * subclass - AbstractHtmlFinder is abstract and has no logic of its own
 * worth testing in isolation from a parseDocument() implementation.
 */
class AbstractHtmlFinderTest extends TestCase
{
    private const PAGE_URL = 'https://pde-client-tests.example/enrollment';

    public function test_find_excludes_links_inside_chrome_elements(): void
    {
        Http::fake([
            self::PAGE_URL => Http::response($this->htmlWithChromeAndContentLinks()),
        ]);

        $finder = $this->makeFinder();

        $files = $finder->find();

        $this->assertCount(1, $files);
        $this->assertStringContainsString('public-school', $files->first()->url);
    }

    public function test_find_throws_page_fetch_exception_on_failed_response(): void
    {
        Http::fake([
            self::PAGE_URL => Http::response('', 500),
        ]);

        $finder = $this->makeFinder();

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('responded with status 500');

        $finder->find();
    }

    public function test_find_translates_a_connection_exception_into_page_fetch_exception(): void
    {
        Http::fake([
            self::PAGE_URL => fn () => throw new ConnectionException('boom'),
        ]);

        $finder = $this->makeFinder();

        $this->expectException(PageFetchException::class);
        $this->expectExceptionMessage('boom');

        $finder->find();
    }

    public function test_find_caches_the_result_so_a_second_call_does_not_refetch(): void
    {
        Http::fake([
            self::PAGE_URL => Http::response($this->htmlWithChromeAndContentLinks()),
        ]);

        // A TTL of 0 expires immediately on most cache drivers rather than
        // "forever" - a real positive TTL is needed here to actually prove
        // the second find() is served from cache instead of refetching.
        $finder = $this->makeFinder(cache: $this->app->make(CacheRepository::class), cacheTtlSeconds: 3600);

        $first = $finder->find();
        $this->assertCount(1, $first);
        $this->assertCount(1, Http::recorded());

        $second = $finder->find();
        $this->assertCount(1, $second);
        $this->assertSame($first->first()->url, $second->first()->url);

        // Still just one recorded HTTP request - the second find() was served from cache.
        $this->assertCount(1, Http::recorded());
    }

    private function makeFinder(?CacheRepository $cache = null, int $cacheTtlSeconds = 0): EnrollmentFileFinder
    {
        return new EnrollmentFileFinder(
            pageUrl: self::PAGE_URL,
            http: $this->app->make(HttpFactory::class),
            cache: $cache,
            cacheTtlSeconds: $cacheTtlSeconds,
        );
    }

    private function htmlWithChromeAndContentLinks(): string
    {
        return <<<'HTML'
            <html>
                <body>
                    <nav>
                        <a href="https://pde-client-tests.example/enrollment/public-school/nav-decoy.xlsx">Nav decoy</a>
                    </nav>
                    <aside>
                        <a href="https://pde-client-tests.example/enrollment/public-school/aside-decoy.xlsx">Aside decoy</a>
                    </aside>
                    <main>
                        <a href="https://pde-client-tests.example/enrollment/public-school/2024-2025.xlsx">2024-2025 Public School Enrollment</a>
                    </main>
                    <footer>
                        <a href="https://pde-client-tests.example/enrollment/public-school/footer-decoy.xlsx">Footer decoy</a>
                    </footer>
                </body>
            </html>
            HTML;
    }
}
