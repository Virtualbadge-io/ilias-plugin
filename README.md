# Virtualbadge.io for ILIAS

Issue digital certificates and badges with [Virtualbadge.io](https://www.virtualbadge.io) when learners complete an ILIAS course.

Tutors add a **Virtualbadge.io** object to a course, choose a certificate or badge template of their Virtualbadge.io organisation and map the template fields to user, course and completion data. When a learner meets the condition, the plugin sends the certificate to Virtualbadge.io in the background and shows the learner the link to the certificate and its validation page.

Virtualbadge.io issues certificates and badges as Open Badges and W3C Verifiable Credentials, and for the EU Digital Identity Wallet (EUDI Wallet) as SD-JWT VC and mdoc. Learners can add them to the EUDI Wallet or save them to the wallet app on their smartphone.

- Plugin ID: `xvbd` (repository object plugin)
- Source code and releases: `https://github.com/Virtualbadge-io/ilias-plugin`
- Guides for administrators and tutors: [Wiki](https://github.com/Virtualbadge-io/ilias-plugin/wiki)
- Bug tracker: `https://github.com/Virtualbadge-io/ilias-plugin/issues`
- License: GPL-3.0-or-later, see [LICENSE](LICENSE)

## Features

- Triggers per object:
  - **Course completion**: learning progress of the course is "completed".
  - **Course completion, after release by a tutor**: the status list shows who completed the course, a tutor releases the certificates (single, selected or all).
  - **Completion of an object**: a chosen object of the course is completed, for example the final test. For tests optionally a minimum result in percent.
  - **Manual release** by a tutor, without condition.
- Issue for learners who met the condition before the object was added.
- Status list of all participants with release, retry, reissue (replaces the certificate in Virtualbadge.io) and "send email again".
- Field mapping: first and last name, full name (with academic title), academic title, email, login, matriculation number, institution, department, city, country, custom user fields that are allowed in certificates, course title, description, category, course period, custom metadata of the course, completion date, test result (percent, points, maximum points) and fixed text with placeholders. Every mapped value must be filled in; see [Field mapping](#field-mapping).
- Several Virtualbadge.io organisations with their own API key; courses are mapped to an organisation by category.
- Own hourly limit of API calls per API key, automatic retries, status checks of certificates that are being created (once a day after the regular checks) and a daily check of issued certificates (revoked, replaced or deleted in Virtualbadge.io).
- Learning progress: the object can be "completed" once the certificate is issued.
- English and German.

## Requirements

- A Virtualbadge.io account with API access and its API key. Virtualbadge.io is an external, paid service for issuing and validating digital certificates and badges; templates, issuing, links and validation pages come from Virtualbadge.io. The plugin itself is free. The API key is shown in the Virtualbadge.io admin panel under Account Settings, Integration.
- ILIAS and PHP, see the table below. PHP extensions `curl`, `sodium`, `mbstring`, `json` (all required by ILIAS anyway).
- The ILIAS cron job must run (`php cli/cron.php run-jobs <user> <client_id>`), ideally every minute.

| Branch | ILIAS | PHP | Status |
|---|---|---|---|
| `release_10` | 10.x | 8.2 – 8.3 | supported |
| `release_11` | 11.x | 8.3 – 8.4 | supported |
| `release_12` | 12.x | 8.4 – 8.5 | planned |

## Installation

Run the commands in the ILIAS root directory as the web server user. Use the branch matching your ILIAS version.

```bash
mkdir -p public/Customizing/global/plugins/Services/Repository/RepositoryObject
git clone -b release_11 https://github.com/Virtualbadge-io/ilias-plugin \
    public/Customizing/global/plugins/Services/Repository/RepositoryObject/Virtualbadge

# The plugin has no runtime dependencies. Rebuild the ILIAS autoloader and artifacts:
composer dump-autoload --no-dev

# Install, update and activate the plugin
php cli/setup.php update --legacy-plugin=Virtualbadge
```

Alternatively install and activate it in **Administration > Extending ILIAS > Plugins**.

After activation:

1. **Administration > Extending ILIAS > Plugins > Virtualbadge > Configure**: enter the API key of your Virtualbadge.io organisation and click **Test connection**.
2. **Administration > System Settings and Maintenance > General Settings > Cron Jobs**: check that the jobs *Virtualbadge.io: issue certificates* (every minute) and *Virtualbadge.io: check issued certificates* (hourly) are active.
3. Learning progress must be enabled (**Administration > Learning Progress**): the triggers use the learning progress of courses and objects.

On its first activation the plugin gives the new object type default permissions, following the ILIAS initial permission guideline:

| Role (template) | Permissions on Virtualbadge.io objects |
|---|---|
| Course and group administrator, Author | all, including create in courses, groups and folders |
| Course tutor | visible, read, write (status list), copy, create in courses and folders |
| Course and group member, global role User | visible, read (own certificate) |
| Local Administrator | visible, delete, edit permissions |

The permissions are added to the role templates and to the local roles of existing courses and groups, unless a role already has settings for the type. Later changes by an administrator are kept: the plugin does this only once.

## Configuration

| Setting | Meaning |
|---|---|
| API address | `https://api.virtualbadge.io`; change only if requests go through a proxy. The ILIAS proxy settings are used. |
| API key | Key of the main organisation. Stored encrypted, never shown again. Leave empty to keep the stored key. |
| Calls per hour | Own limit per API key (default 200, 0 = no limit). Further certificates wait and are sent automatically. |
| Date format | Format of dates in template fields. |
| Organisations | Further organisations, one per line `key|name`. Each gets its own API key and limit. |
| Categories | `category = organisation key` per line, category by ref id or import id. Courses use the nearest mapped category, otherwise the main organisation. |

The key used to encrypt the API keys is stored in `<client data directory>/xvbd/secret.key` (outside the web root). If this file is lost, enter the API keys again.

In a course, add the object *Virtualbadge.io*, choose organisation and template, the trigger and map the template fields.

## Field mapping

Every text field of the chosen template gets a value from one of these sources. The plugin suggests a source from the template field name; the tutor can change it in the settings of the object.

| Group | Sources |
|---|---|
| Same for all | Fixed text, with placeholders such as `{firstname}`, `{course}`, `{completiondate}` or `{coursefield:ID}` (the full list is shown below the text field) |
| Participant | First name, last name, full name, academic title, email, login, matriculation number, institution, department, city, country, custom user fields that are allowed in certificates |
| Course | Title, description, category, start and end date |
| Course metadata | Every custom metadata field of the course, shown as "Course metadata: record – field" |
| Completion | Completion date, test result (percent, points, maximum points) |

Values are read when the certificate is sent, so a reissue uses the current values.

**Empty values**: every template field mapped to a participant, course, course metadata or completion source must have a value. If it is empty, the certificate is not sent: the status list shows an error naming the template field and the source, and the tutor can choose "Retry issuing" once the value is filled in. There is no exception per field, so only map sources that every learner and the course have, for example the academic title only if all learners have one. Fixed text is not checked: it is sent as it results after replacing the placeholders, even if that is empty.

**Full name**: the source "Full name (with academic title)" and the placeholder `{fullname}` use the full name as ILIAS builds it, with the academic title if the learner has one ("Dr. Max Muster", without title "Max Muster"). This is the same name ILIAS prints on its own certificates. The recipient name sent to Virtualbadge.io, which is printed as the name on the certificate, is built the same way.

### Course metadata

Courses can print their own data on certificates, for example continuing education points, a course number or the venue, using ILIAS custom metadata:

1. An administrator creates a custom metadata record for the object type *Course* in the ILIAS metadata administration (tab *Custom Metadata*), adds the fields and activates the record. A record limited to certain categories is only available in courses below them.
2. In the course, the fields are filled in on the *Metadata* tab. Records created in the course itself are only used if the course has custom metadata switched on; optional records must also be selected there.
3. In the settings of the Virtualbadge.io object, the tutor maps a template field to a source of the group "Course metadata", or uses the placeholders listed above the field mapping (`{coursefield:ID}`) in fixed text.

The object offers exactly the fields the course itself shows. Field and record titles appear in the tutor's language. Values are converted to text:

| Field type | Text on the certificate |
|---|---|
| Text, integer | as entered (without unit) |
| Float | with the configured number of decimals and the decimal separator of the ILIAS default language (without unit) |
| Date, date and time | the date format of the plugin configuration (date only) |
| Select, multi-select | the option label in the record's default language; several labels separated by ", " |
| External link | the URL, or "title (URL)" if a title is set |
| Internal link | the title of the linked object |
| Location, address | the text ILIAS shows, without formatting |

If a mapped field is no longer available in the course (record deactivated or limited to other categories, field deleted, course copied to a place where the record is not active), tutors see a warning on the object page and in the settings naming the template fields that use it, directly or as a placeholder. Certificates using such a field end with an error instead of an empty value until another source is chosen. A placeholder of such a field in fixed text stays empty.

## Update

```bash
cd public/Customizing/global/plugins/Services/Repository/RepositoryObject/Virtualbadge
git pull
cd -   # back to the ILIAS root
composer dump-autoload --no-dev
php cli/setup.php update --legacy-plugin=Virtualbadge
```

When you update ILIAS to a new major version, switch the plugin to the matching branch (`git checkout release_11`) before running the ILIAS update.

## Uninstall

Uninstall the plugin in **Administration > Extending ILIAS > Plugins** while it is still active. ILIAS deletes the Virtualbadge.io objects first and refuses this for a deactivated plugin ("Please activate the listed plugin(s) before deleting their objects"); if the plugin is already deactivated, activate it again first. Uninstalling deletes all Virtualbadge.io objects, the plugin tables, settings and the key file. Certificates already issued in Virtualbadge.io stay valid. Then remove the plugin directory.

## Privacy

To issue a certificate, the plugin sends to Virtualbadge.io: the full name (with academic title, if set), email address, the issue date, the values of the mapped template fields (which can include the academic title, custom user fields and course metadata) and a reference (hash of the ILIAS installation, object id, user id, issue id). The plugin stores per user and object: status, links to the certificate and validation page, times of completion and issue. When an ILIAS user is deleted, these records are deleted. Deleting data in ILIAS does not revoke certificates in Virtualbadge.io.

## Bug reports

Please report bugs in the GitHub issue tracker: `https://github.com/Virtualbadge-io/ilias-plugin/issues`. Include ILIAS version, plugin version and the message shown in the status list or the ILIAS log.

## Development

The version independent core in `src/Core` has unit tests that run without ILIAS:

```bash
composer install
vendor/bin/phpunit
```

`src/Ilias` and `classes/` are the ILIAS layer. Classes marked *VERSION SPECIFIC* differ between the release branches.
