# The image of a workspace box: the language toolchains (from Sail's PHP and
# Node image), the box runner, the coding agent runner, the preview tools,
# the screen check with its browser, the shortcut scan and the trace
# recorder. Nothing else of the control plane goes in. Build it with `vendor/bin/sail build box` (the
# context is resources/).
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

# On a runner machine the runner fences workspaces off from each other's
# previews and from the private network with iptables (box-runner/runner.mjs).
RUN apt-get update \
    && apt-get install -y --no-install-recommends iptables \
    && rm -rf /var/lib/apt/lists/*

# The shortcut scan reads the PHP a change touched with the Sloppy analyser.
# It lives here, pinned and checked, so nothing is added to the app itself.
ARG SLOPPY_VERSION=1.1.1
ARG SLOPPY_SHA256=fd47566df2f79a5fde12224045e91a93d00ce8f758b4f63723928079746e45b7
RUN mkdir -p /opt/sloppy \
    && curl -fsSL -o /opt/sloppy/sloppy.phar "https://github.com/Heyosseus/sloppy/releases/download/v${SLOPPY_VERSION}/sloppy.phar" \
    && echo "${SLOPPY_SHA256}  /opt/sloppy/sloppy.phar" | sha256sum -c -

COPY agent-runner/run.mjs /opt/agent-runner/run.mjs
COPY box-runner/runner.mjs /opt/box-runner/runner.mjs
COPY preview-tools/ /opt/preview-tools/
COPY screen-check/check.mjs /opt/screen-check/check.mjs

# The trace recorder notes what each request of a test asks, saves and sends.
# It is loaded from here when the coverage run asks for it, so nothing is
# added to the app itself.
COPY trace-recorder/ /opt/trace-recorder/

RUN mkdir -p /workspaces

ENTRYPOINT ["node", "/opt/box-runner/runner.mjs"]
