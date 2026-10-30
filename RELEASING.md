# Releasing poli-page/symfony-bundle

The bundle is published on [Packagist](https://packagist.org) as **`poli-page/symfony-bundle`** from the repository `poli-page/symfony`, the same way as [`poli-page/sdk`](https://github.com/poli-page/sdk-php). Packagist has no upload: it reads the repository, and the version comes from the git tag, so `composer.json` has no `version` field.

## How a release runs

Pushing a tag `vX.Y.Z` (or a prerelease `vX.Y.Z-rc1`) starts `.github/workflows/release.yml`:

1. **test**: the whole `ci.yml` matrix on the tagged commit (PHP-CS-Fixer, PHPStan, unit tests, the API contract suite with the pinned `poli-page/api-contract`, the example-app build).
2. **Version matches tag**: `composer validate --strict`, no `version` field in `composer.json`, a `## [X.Y.Z]` section in `CHANGELOG.md`, and the example app's path-repository version of `poli-page/symfony-bundle` equal to the tag.
3. **Publish to Packagist**: waits in the `packagist-release` environment until a reviewer approves, then calls Packagist's `update-package` API with the `PACKAGIST_USERNAME` and `PACKAGIST_API_TOKEN` secrets, and creates the GitHub Release with the CHANGELOG section as notes.

The approval is the publish: Packagist indexes the tag when the workflow pings it. Do not install the Packagist auto-update webhook on this repository, or the tag push alone would publish it before tests and approval. Packagist also re-crawls packages without a webhook from time to time, so approve (or delete the tag) soon after the checks finish.

The dist archive leaves out every `export-ignore` path in `.gitattributes` (tests, the example app, the Flex recipe source, CI and tooling files). Check what ships with:

```bash
git archive HEAD | tar -t
```

## One-time setup (before the first release)

`poli-page/symfony-bundle` was free on Packagist on 2026-10-30 (the `poli-page` vendor held only `poli-page/sdk`).

1. On packagist.org, signed in as the account that maintains `poli-page/sdk`, open <https://packagist.org/packages/submit> and submit `https://github.com/poli-page/symfony` (the repository's current URL: the release workflow pings Packagist with it). Packagist names the package from `composer.json`: `poli-page/symfony-bundle`. Do it **before** pushing the first tag: Packagist then lists only the `dev-*` branches, and `0.11.0` appears when the release workflow pings it.
2. Leave the package without the GitHub auto-update hook (Packagist shows a "not auto-updated" notice; that is intended). If the Packagist account syncs GitHub repositories automatically, turn that off for this repository.
3. In GitHub, `poli-page/symfony` → Settings → Environments → **New environment** `packagist-release`:
   - Required reviewers: the release owner(s).
   - Deployment branches and tags: **Selected branches and tags**, add the tag rule `v*`.
4. In that environment, add two secrets. They hold the same values as the `PACKAGIST_USERNAME` and `PACKAGIST_API_TOKEN` repository secrets of `poli-page/sdk-php`; GitHub never shows a secret's value, so enter them again from Packagist:
   - `PACKAGIST_USERNAME`: the Packagist username of that account.
   - `PACKAGIST_API_TOKEN`: its **safe** API token from <https://packagist.org/profile/> (the `update-package` endpoint accepts it).

## Each release

1. On a branch off `main`:
   - Move `[Unreleased]` in `CHANGELOG.md` to `## [X.Y.Z] - YYYY-MM-DD`, leave an empty `## [Unreleased]` above it, and update the compare links at the bottom.
   - Set the example app's version of the package to `X.Y.Z`: `repositories[0].options.versions["poli-page/symfony-bundle"]` in `example-app/composer.json`, and the two `v…` labels in `example-app/templates/demo.html`.
   - Update the compatibility table in `README.md` when the minor version or the supported Symfony/PHP versions change.
2. Open a PR, wait for CI to be green, merge it.
3. Tag the merge commit on `main` and push the tag:

   ```bash
   git switch main && git pull
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

4. In the Actions tab, wait for **test** and **Version matches tag** to pass, then approve the `packagist-release` deployment.
5. Check <https://packagist.org/packages/poli-page/symfony-bundle> lists `X.Y.Z`, and that `composer require poli-page/symfony-bundle:^X.Y` installs it in an empty project.

A failed check before the approval publishes nothing: fix it on `main`, delete the tag (`git push --delete origin vX.Y.Z && git tag -d vX.Y.Z`) and tag again. Once Packagist has indexed a version, never move its tag; release a new patch version instead.

## Symfony Flex recipe

`recipes/` holds the source of the Flex recipe (register the bundle, add `config/packages/poli_page.yaml`, add `POLI_PAGE_API_KEY` to `.env`). Flex reads recipes from [`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib), not from the package, so after the first release on Packagist submit it there as `poli-page/symfony-bundle/0.11/` (steps in [`recipes/README.md`](recipes/README.md)). Until it is merged, users register the bundle and add the config by hand, as the README shows.
