<?php

namespace WiserWebSolutions\PDEClient\Tests\Support;

use PHPUnit\Framework\TestCase;
use WiserWebSolutions\PDEClient\Support\HtmlDocument;

class HtmlDocumentTest extends TestCase
{
    private function document(): HtmlDocument
    {
        $html = <<<'HTML'
            <html>
                <body>
                    <nav><a href="/nav-link.xlsx">Nav link</a></nav>
                    <main>
                        <h2>Revenues</h2>
                        <a href="report.xlsx">Report</a>
                    </main>
                </body>
            </html>
            HTML;

        return new HtmlDocument($html, 'https://www.example.com/content/dam/reports/index.html');
    }

    public function test_query_finds_expected_elements_via_xpath(): void
    {
        $links = $this->document()->query('//a');

        $this->assertCount(2, $links);
    }

    public function test_query_can_exclude_chrome_elements(): void
    {
        $links = $this->document()->query('//a[not(ancestor::nav)]');

        $this->assertCount(1, $links);
        $this->assertSame('report.xlsx', $links[0]->getAttribute('href'));
    }

    public function test_absolute_url_leaves_an_already_absolute_href_unchanged(): void
    {
        $document = $this->document();

        $this->assertSame(
            'https://other.example.com/file.xlsx',
            $document->absoluteUrl('https://other.example.com/file.xlsx'),
        );
    }

    public function test_absolute_url_resolves_protocol_relative_href(): void
    {
        $document = $this->document();

        $this->assertSame(
            'https://cdn.example.com/file.xlsx',
            $document->absoluteUrl('//cdn.example.com/file.xlsx'),
        );
    }

    public function test_absolute_url_resolves_root_relative_href(): void
    {
        $document = $this->document();

        $this->assertSame(
            'https://www.example.com/content/dam/other/file.xlsx',
            $document->absoluteUrl('/content/dam/other/file.xlsx'),
        );
    }

    public function test_absolute_url_resolves_bare_relative_href_against_base_directory(): void
    {
        $document = $this->document();

        $this->assertSame(
            'https://www.example.com/content/dam/reports/file.xlsx',
            $document->absoluteUrl('file.xlsx'),
        );
    }
}
