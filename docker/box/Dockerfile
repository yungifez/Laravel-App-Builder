# The image of a workspace box: the language toolchains (from Sail's PHP and
# Node image), the box runner, the coding agent runner, the preview tools and
# the screen check with its browser. Nothing else of the control plane goes in. Build it with
# `vendor/bin/sail build box` (the context is resources/).
FROM sail-8.4/app

COPY agent-runner/package.json agent-runner/package-lock.json /opt/agent-runner/
RUN cd /opt/agent-runner && npm ci --omit=dev --no-audit --no-fund

# The screen check opens the app's pages in Chromium at phone, tablet and
# computer widths. The browser sits outside any home folder so the box's user
# can run it.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
COPY screen-check/package.json screen-check/package-lock.json /opt/screen-check/
RUN cd /opt/screen-check && npm ci --omit=dev --no-audit --no-fund \
    && npx playwright-core install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/*

COPY agent-runner/run.mjs /opt/agent-runner/run.mjs
COPY box-runner/runner.mjs /opt/box-runner/runner.mjs
COPY preview-tools/ /opt/preview-tools/
COPY screen-check/check.mjs /opt/screen-check/check.mjs

RUN mkdir -p /workspaces

ENTRYPOINT ["node", "/opt/box-runner/runner.mjs"]
