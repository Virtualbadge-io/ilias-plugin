# Changelog

All notable changes to the Virtualbadge.io plugin for ILIAS. Versions are the same on all release branches.

## 1.0.1 (2026-11-23)

First version.

- Repository object "Virtualbadge.io" for courses, groups and folders in courses.
- Triggers: course completion, course completion with release by a tutor, completion of an object (optionally with minimum test result), manual release.
- Status list with release, retry, reissue and resend email; issue for past completions.
- Field mapping with user, custom user, course and completion sources and fixed text with placeholders.
- Custom metadata of the course (ILIAS "Custom Metadata") as field source and as `{coursefield:ID}` placeholders in fixed text; values converted to text by field type.
- Academic title of the profile as field source.
- Every empty mapped value stops the certificate with an error naming the template field; fixed text is sent as it is.
- Source "Full name (with academic title)" and placeholder `{fullname}` and the recipient name sent to Virtualbadge.io use the full name as ILIAS builds it, with academic title ("Dr. Max Muster").
- Messages in the status list (empty field, unavailable source, deleted object, inactive template, API key, hourly limit and others) in the ILIAS default language, English or German; texts from Virtualbadge.io are shown unchanged.
- Warning on the object page and in the settings when a mapped field is no longer available in the course; such certificates end with an error instead of an empty value.
- Several Virtualbadge.io organisations, mapping by category, encrypted API keys, hourly limit per key.
- Cron jobs for issuing, status checks and daily verification. Certificates still being created after the regular status checks are checked once a day (e.g. until the organisation has credits again); a failed generation in Virtualbadge.io ends with an error, and retrying it creates a new certificate.
- Learning progress "completed when issued".
- Default permissions for course and group roles on the first activation (ILIAS initial permission guideline).
- English and German.
