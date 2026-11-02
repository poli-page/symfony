# Symfony Flex recipe source

The files here are the canonical source for the Symfony Flex recipe shipped at:

  `github.com/symfony/recipes-contrib/tree/main/poli-page/symfony/0.13`

Process for submitting / updating:

1. Tag a release of `poli-page/symfony` on Packagist.
2. Fork [`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib).
3. Create the directory `poli-page/symfony/<major.minor>/` in your fork.
4. Copy `manifest.json` + `config/` from this folder into it.
5. Open a PR. CI on the contrib repo validates the recipe shape.

The recipe is intentionally minimal: register the bundle, create a one-line config file, append the env var. Anything more should live in the bundle's README, not the recipe.
