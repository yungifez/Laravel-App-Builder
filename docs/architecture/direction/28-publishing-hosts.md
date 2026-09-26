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
