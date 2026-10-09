# Direction 28: Publishing hosts, with Laravel Cloud for Grandma

> Source: direction from the project owner, recorded as given (formatting repaired
> only). It is part of a build session on 2026-09-26. It follows the assistant's
> recommendation to finish the publish loop next: smoke checks against the
> published app, basic error intake per release, and one plain health state.

**Assistant (summary):**

The publish loop is the one required V1 piece that is not complete. Today a
publish pushes to a branch and then checks that a few addresses answer.

**Owner:**

Lets keep it open to different providers, but for grandma, we would use laravel
cloud.

**Owner:**

Then we literally need a way for them to directly pass their cost to Laravel
cloud easily, check the cloud cli

**Assistant (summary):**

The Cloud CLI and its public API cover creating an app with a database,
deploying, deploy status, environment variables, logs, domains and usage per
application. Two things stop Grandma from paying Cloud directly. There is no
sign-in for platforms: the API takes only organization tokens that the owner
creates and pastes. And Cloud deploys only from a repository the owner's own
Git account can read, while our repositories live in our private organization.
Apps cannot move between Cloud organizations later. The recommendation: host
Grandma's apps in our Cloud organization and pass the exact cost through from
the usage API; let power users connect their own Cloud organization with a
scoped token and deploy from their own repository; ask Laravel later for a
sign-in for platforms.

**Owner:**

Sure lets do it without asking Laravel for now

**Owner:**

FOR NOW, LETS JUST EAT THE COST
