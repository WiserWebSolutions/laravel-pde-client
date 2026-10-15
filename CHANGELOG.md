# Changelog

## Unreleased

- Include public PDE listing and file citations on every parsed record and year summary, with per-field references through combined datasets, derived values, cache round trips, and JSON hydration.
- Add safe sourcesFor() inspection, sourced totals/amounts/variance, and discovery page URLs on RemoteFile.
- Preserve verified source manifests for raw downloads and parsed queries; refresh unverified cached files and version parsed caches.
- Cite the verified PDE accounting manual for bundled chart definitions; extend all 17 dataset examples and 13 optional migration templates with provenance.

## v5.0.0 — 2026-10-14

- Throw DataNotCollectedException when recognized reports omit requested fields or demographics; allow return_null through published configuration.
- Preserve typed readonly property access and availability reasons through parsing, caches, DTO hydration, and Spatie exports.
- Add immutable MetricMap values, safe availability()/hasData() inspection, and complete-input derived totals.
- Return collections consistently from get(); provide flat records() imports and explicit financial/enrollment aggregation.
- Validate AUNs and fiscal years, scope workbook loading, avoid repeated account rollups, and index account relationships.
- Keep HTTP, parsing, and filesystem failures visible; add transient retries, checked streams, source-aware refreshes, and versioned caches.
- Document all 17 supported datasets with executable synthetic examples, plus configuration, year selection, and eight raw file accessors.
- Repair optional persistence templates and add Pint, PHPStan, and PHP/Laravel compatibility CI.

See [the upgrade guide](docs/upgrade.md) for breaking API changes and [the roadmap](docs/roadmap.md) for future package ideas.
