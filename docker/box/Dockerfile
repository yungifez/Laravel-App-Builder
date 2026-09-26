# The image of a workspace box: the language toolchains (from Sail's PHP and
# Node image), the box runner, the coding agent runner and the preview tools.
# Nothing else of the control plane goes in. Build it with
# `vendor/bin/sail build box` (the context is resources/).
FROM sail-8.4/app

COPY agent-runner/package.json agent-runner/package-lock.json /opt/agent-runner/
RUN cd /opt/agent-runner && npm ci --omit=dev --no-audit --no-fund

COPY agent-runner/run.mjs /opt/agent-runner/run.mjs
COPY box-runner/runner.mjs /opt/box-runner/runner.mjs
COPY preview-tools/ /opt/preview-tools/

RUN mkdir -p /workspaces

ENTRYPOINT ["node", "/opt/box-runner/runner.mjs"]
