<?php

namespace App\Workspaces\Machines;

use InvalidArgumentException;

/**
 * The script a new cloud machine runs once at its first boot. It sets the
 * machine up the way docs/runner-machines.md does by hand: Docker, the
 * runner's settings in a file only root reads, and the runner as a
 * service that starts at boot. The same script serves every cloud; the
 * cloud only says how the machine finds its own service address.
 */
class RunnerBootScript
{
    /**
     * Build the boot script for one runner. With a preview door port, the
     * runner opens its preview door there, for a control plane that shares
     * no private network with it. With a list of hosts, workspaces reach
     * only those and the control plane; with the fence required, the
     * runner does not start where it cannot set its firewall.
     */
    public function make(string $controlPlaneUrl, string $token, string $boxImage, string $serviceHostCommand, ?int $previewDoorPort = null, ?string $egressAllow = null, bool $requireFence = false): string
    {
        $door = $previewDoorPort === null ? '' : "RUNNER_PREVIEW_DOOR_PORT={$previewDoorPort}\n";

        if (filled($egressAllow) && ! preg_match('#^[A-Za-z0-9.,-]+$#', $egressAllow)) {
            throw new InvalidArgumentException('The list of hosts workspaces may reach cannot go into a boot script as it is.');
        }

        $door .= (filled($egressAllow) ? "RUNNER_EGRESS_ALLOW={$egressAllow}\n" : '').($requireFence ? "RUNNER_REQUIRE_FENCE=on\n" : '');

        foreach (['control plane address' => $controlPlaneUrl, 'token' => $token, 'box image' => $boxImage] as $what => $value) {
            // Each goes into the script as written, so it must stay one
            // plain word: no spaces, quotes or other shell characters.
            if (! preg_match('#^[A-Za-z0-9._:/@-]+$#', $value)) {
                throw new InvalidArgumentException("The {$what} cannot go into a boot script as it is.");
            }
        }

        return <<<SH
            #!/bin/bash
            set -euo pipefail

            if ! command -v docker >/dev/null; then
                curl -fsSL https://get.docker.com | sh
            fi

            service_host="\$({$serviceHostCommand})"
            if [ -z "\$service_host" ]; then
                echo "Could not find this machine's service address." >&2
                exit 1
            fi

            install -m 600 /dev/null /etc/builder-runner.env
            cat > /etc/builder-runner.env <<'ENV'
            RUNNER_URL={$controlPlaneUrl}
            RUNNER_TOKEN={$token}
            {$door}ENV
            echo "RUNNER_SERVICE_HOST=\$service_host" >> /etc/builder-runner.env

            install -d -m 711 /srv/workspaces

            cat > /etc/systemd/system/builder-runner.service <<'UNIT'
            [Unit]
            Description=Builder runner
            Requires=docker.service
            After=docker.service network-online.target
            Wants=network-online.target

            [Service]
            Restart=always
            RestartSec=5
            ExecStartPre=-/usr/bin/docker rm -f runner
            ExecStart=/usr/bin/docker run --rm --name runner --network host --cap-add NET_ADMIN --env-file /etc/builder-runner.env -v /srv/workspaces:/workspaces {$boxImage}
            ExecStop=/usr/bin/docker stop runner

            [Install]
            WantedBy=multi-user.target
            UNIT

            docker pull {$boxImage}
            systemctl daemon-reload
            systemctl enable --now builder-runner
            SH;
    }
}
