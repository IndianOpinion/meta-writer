# Security policy

## Reporting a vulnerability

Please email hello@indianopinion.org with the details and, if you can, a
way to reproduce it. Do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that exposes keys, content
or write access. We will credit you in the release notes unless you ask us
not to.

## Supported versions

Only the latest release receives security fixes.

## Scope notes

- meta-writer sends post text to the model provider the operator
  configures. That is by design and stated on the settings screen.
- The API key is never printed in full, logged or sent anywhere but the
  chosen provider. A leak of the key through any path is a security bug.
