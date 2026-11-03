# Distribution, package naming, and docs-site options

> Documentation update — 3 November 2026: documentation stays as versioned Markdown in this repository's [docs folder](../README.md). The separate-site recommendation below is historical and has been superseded.

> Update — 2 November 2026: the repository is public. The logged-in Packagist submission found `thunder` owned by another account; Helge selected `helgesverre/slack-bolt`, retaining `Thunder\Bolt` imports. The observations and recommendation below are historical; use the root README for current installation instructions.

Researched 30 October 2026. This is a decision memo, not a registration or
publication plan. It records what the public registries returned on that date;
it does **not** establish ownership, trademark rights, or legal clearance.

## Current position

The local package is a Composer `library` named `thunder/bolt`, with the
provisional `Thunder\Bolt` PSR-4 namespace. Its local Git remote is
`git@github.com:HelgeSverre/slack-bolt-php.git`; the existing `v0.2.0` tag
resolves locally to `1b27a8e`. The generator currently creates an application
with a local `path` repository pointing back to this checkout and requires
`thunder/bolt: 0.2.x-dev`. That is excellent for framework contributors, but
not a credible installation story for adopters: moving or deleting the clone
breaks the generated application.

The owner-authenticated GitHub API reports this repository as **PRIVATE**. The
earlier unauthenticated REST 404 is therefore expected. Treat every VCS install
as credentialed today; it is not a public adoption path.

## Packagist observations

Packagist documents `p2/vendor/package.json` as its preferred current Composer
metadata endpoint. Each of the endpoints below returned HTTP 404 with
`"404 not found, no packages here"` on 30 October 2026. The corresponding
`~dev` endpoints also returned 404, and an exact-name filter over
[`search.json`](https://packagist.org/apidoc) returned zero matches for each:

| Candidate | Observed endpoint |
| --- | --- |
| `thunder/bolt` | <https://repo.packagist.org/p2/thunder/bolt.json> |
| `helgesverre/bolt` | <https://repo.packagist.org/p2/helgesverre/bolt.json> |
| `helgesverre/slack-bolt` | <https://repo.packagist.org/p2/helgesverre/slack-bolt.json> |
| `thunder/slack-bolt` | <https://repo.packagist.org/p2/thunder/slack-bolt.json> |
| `helgesverre/thunder-bolt` | <https://repo.packagist.org/p2/helgesverre/thunder-bolt.json> |

This means no tagged or development package record, or exact search result, was
returned at the time of the check. It does **not** mean the name is reserved,
that a vendor is controlled by Helge, or that the name is safe to brand.
Packagist itself says a vendor becomes protected only after a package under it
is published and that a package name cannot later be changed. [Its publication
guidance](https://packagist.org/about) is the authority for the final,
logged-in check.

## Naming recommendation

Use **`helgesverre/thunder-bolt`** for the first public library release,
provided the Packagist account is Helge-controlled and the final logged-in
availability check succeeds. Keep the PHP namespace as provisional
`Thunder\Bolt`; Composer package identity and PHP namespace do not have to
match.

Why this is the least surprising first move:

- `helgesverre/bolt` is short but far too generic to be distinctive in search,
  issue reports, and future packages.
- `thunder/bolt` matches the current manifest but assumes control of the
  generic `thunder` vendor. The public 404 establishes neither that control nor
  brand clearance.
- `*/slack-bolt` is discoverable, but puts Slack's word mark and the established
  Bolt name directly in this project's public product identifier. Slack calls
  Bolt for JavaScript its framework and its brand terms limit use of the Slack
  mark, prohibit names that imply endorsement, and prohibit domains containing
  “slack”. Avoid that ambiguity for the library identifier unless counsel and
  Slack's current guidance say otherwise. See [Bolt JS](https://docs.slack.dev/tools/bolt-js/)
  and [Slack's brand terms](https://slack.com/intl/en-gb/terms-of-service/slack-brand).

`helgesverre/thunder-bolt` is descriptive, auditable against the existing
GitHub owner, and leaves room for related packages. It is a pragmatic package
name, not a legal conclusion. If Thunder Bolt becomes a durable independent
brand, establish a controlled organization/vendor first and then use a matching
`thunderbolt/thunderbolt`-style namespace for future packages; do not rename a
published first package casually.

## Distribution path

### Recommended now: private tagged VCS installation

Keep the local path repository for development of this repository. Approved
collaborators can consume the already-existing tag reproducibly through
Composer's supported VCS repository type, using their normal GitHub/Composer
credentials:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/HelgeSverre/slack-bolt-php"
    }
  ],
  "require": {
    "thunder/bolt": "v0.2.0"
  }
}
```

The package name must remain `thunder/bolt` until the manifest is deliberately
renamed. Pinning `v0.2.0` is preferable to a branch alias for an experimental
adopter: tags provide a stable release point and the generated lock file pins
the resolved source reference. Composer documents both the `vcs` repository
type and `create-project` semantics in its [repository guide](https://getcomposer.org/doc/05-repositories.md#vcs)
and [CLI guide](https://getcomposer.org/doc/03-cli.md#create-project).

This is the right interim path because it needs no registry write, preserves
the preview tag, and removes the hidden filesystem coupling of today's
scaffold. It should be documented as a credentialed collaborator preview, not
presented as a public one-command install.

### Publish after the name decision

Before public Packagist publication, make two intentional decisions: whether
the source repository and its code are meant to be public, and then which
Composer identity will be permanent. Packagist's public submission flow accepts
a public HTTPS repository URL. Only after that visibility decision:

1. choose and validate the final Composer name while logged in;
2. update the root manifest and generator together, then make a fresh tag;
3. submit the now-public GitHub repository and enable Packagist's GitHub update
   hook; and
4. verify `composer create-project`/`composer require` from a clean directory.

Packagist derives versions from VCS tags and recommends SemVer tags and its
GitHub update integration. [Packagist's publishing guide](https://packagist.org/about)
is explicit on all three points. Do not publish the current tag under one name
and then rename: Packagist says package names cannot change.

### A real application skeleton, later

Do not make the framework package itself the `create-project` target. Composer
states that `create-project` creates a project from an existing package—the
equivalent of checking it out then running `composer install`. This repository
is a reusable library, not an application shell.

After the library is on Packagist, create a small independent application
skeleton, for example `helgesverre/thunder-bolt-app`, with Composer type
`project` and a normal requirement on the published library. Then the public
on-ramp is simple and conventional:

```sh
composer create-project helgesverre/thunder-bolt-app my-slack-app
```

The existing `bolt new` command can remain useful as an ergonomic alternative,
but it should emit the normal published dependency, not an absolute local path.
Composer distinguishes `library` from `project`, and describes `path`
repositories as local-directory dependencies mainly useful for monorepos, in
the [schema](https://getcomposer.org/doc/04-schema.md#type) and
[repositories documentation](https://getcomposer.org/doc/05-repositories.md).

## Separate documentation site

Use a small, separate repository (for example
`HelgeSverre/thunder-bolt-docs`) with **VitePress** and deploy its static build
to **GitHub Pages**. This keeps Node-based presentation tooling, navigation,
and search away from the PHP library's release dependencies while preserving a
plain-Markdown authoring model. VitePress supports a standalone nested docs
root, Markdown-to-static routing, and a documented GitHub Pages deployment
path. See [VitePress setup](https://vitepress.dev/guide/getting-started) and
[VitePress deployment](https://vitepress.dev/guide/deploy).

GitHub Pages is enough for the first public site: it is available for public
repositories and supports a verified custom domain when one is wanted. See the
[GitHub Pages quickstart](https://docs.github.com/en/pages/quickstart) and
[custom-domain guidance](https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/about-custom-domains-and-github-pages).

Start the site with only the material that makes adoption safe: installation,
minimal HTTP and Socket Mode apps, signing/OAuth, interaction acknowledgements,
durable jobs, deployment, and migration/release notes. Keep internal parity
audits and generator research in this repository. Generate a full API reference
only after the public package identity and the v0.x API have settled.

## Decision

1. Continue immediate collaborator preview adoption through the private
   `v0.2.0` VCS tag with normal repository credentials.
2. Do not use Packagist until an explicit public-source/code decision is made.
   Then prefer `helgesverre/thunder-bolt` over the current generic
   `thunder/bolt`; retain `Thunder\Bolt` until a separately approved namespace
   migration.
3. Publish a separate `type: project` skeleton only after the library has a
   stable public package name.
4. When documentation work begins, use a standalone VitePress site on GitHub
   Pages; do not make docs-site setup a prerequisite for the package preview.
