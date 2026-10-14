# Package roadmap

[Documentation index](index.md)

The following are future additions, outside this release:

- A dataset catalog exposing supported years, fields, demographic capabilities, and source evidence.
- Artisan diagnostics plus dataset-scoped warm, refresh, clear, and offline-cache commands.
- Multi-district queries and streamed CSV/JSON exports that retain availability reasons.
- An optional persistence adapter with queued imports, unique natural keys, upserts, and resumable progress.
- Source revision/provenance tracking and comparison APIs that refuse incompatible measurements.
- Additional PDE datasets after their layouts, units, historical coverage, and privacy markers are backed by fixtures.

Prioritize the catalog and diagnostics first: they help consumers choose valid reports and understand gaps before adding more data.
