#!/bin/sh
# Stage dist/ under /docs for a standalone Worker. Root redirects to /docs/.
set -e
rm -rf .deploy
mkdir -p .deploy/docs
cp -r dist/. .deploy/docs/
sed 's#^/_astro/\*#/docs/_astro/*#' .deploy/docs/_headers > .deploy/_headers
rm .deploy/docs/_headers
cp .deploy/docs/404.html .deploy/404.html
{
  printf '/ /docs/ 302\n'
  printf '/docs/guides/decisions /docs/system-one/clef/ 301\n'
  printf '/docs/guides/decisions/ /docs/system-one/clef/ 301\n'
} > .deploy/_redirects
