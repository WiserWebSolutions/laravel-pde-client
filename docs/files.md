# Raw file discovery and downloading

[Documentation index](index.md)

These accessors return file-discovery objects, separate from the parsed dataset queries:

```php
use WiserWebSolutions\PDEClient\Facades\PDE;

PDE::gfb()->get(); // Collection<RemoteFile>
PDE::afr()->get(); // Collection<RemoteFile>
PDE::enrollmentFiles()->get(); // Collection<RemoteFile>
PDE::assessmentFiles()->get(); // Collection<RemoteFile>
PDE::graduationFiles()->get(); // Collection<RemoteFile>
PDE::personnelFiles()->get(); // Collection<RemoteFile>
PDE::financialDataElementsFiles()->get(); // Collection<RemoteFile>
PDE::actOneIndexFiles()->get(); // Collection<RemoteFile>
```

All support get(), first(), sole(), category(), matching(), and download(). category() matches a published category label case insensitively; matching() searches the file label case insensitively. Discover labels with get() before choosing filters. Only gfb() adds schoolYear() and latest(); the other sources expose their published labels/categories.

```php
$files = PDE::gfb()->schoolYear('2024-25')->get();
$file = PDE::gfb()->schoolYear('2024-25')->sole();
$url = $file->url;
$listingPage = $file->pageUrl;
$name = $file->filename();

$paths = PDE::gfb()->schoolYear('2024-25')
    ->download(directory: 'pde-imports', disk: 'local'); // Collection<string>, disk-relative paths
```

Synthetic discovery shape:

```json
{
    "label": "2024-25 GFB Data",
    "url": "https://fixtures.example/gfb-2024-25.xlsx",
    "category": null,
    "period": "2024-25",
    "filename": "gfb-2024-25.xlsx",
    "page_url": "https://fixtures.example/reports/gfb"
}
```

RemoteFile is immutable. toArray() includes label, url, category, period, filename, and page_url. Discovered files include the actual listing page, including cached discovery. Manually constructed files can omit pageUrl; period/category may also be null. Filename extraction decodes the URL path and excludes query strings. Listing URLs are resolved with standard URI rules, including ports, parent paths, uppercase spreadsheet extensions, and download query strings.

Raw download() explicitly downloads the selected files each time. Parsed queries use LocalWorkbookStore to reuse copies according to download_cache_ttl, track source identity, and obtain local files for remote disks. Source manifests accompany stored workbooks; temporary remote-disk copies are isolated and cleaned up when their scoped store is released. Failed writes raise StorageFailedException.

The built-in downloader writes a `.pde-source.json` sidecar for raw downloads as well. Parsed queries verify cached bytes and retain the page, label, period, URL, content hash, and retrieval time in record citations. See [source citations](sources.md).

File discovery is mutable for the caller’s fluent filters, but internal locators clone sources so a prior year/category lookup cannot contaminate later discovery. Empty listing discoveries are retried on later source instances rather than cached for the full TTL.
