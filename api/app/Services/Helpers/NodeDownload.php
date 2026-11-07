<?php

namespace App\Services\Helpers;

/**
 * The download of RunR's node installers (gVisor's release tarball, the WebAssembly shims'
 * tarballs): a shell function `download <url> <file>` for their scripts.
 *
 * wget with DOWNLOAD_TRIES (default 5) tries, 5 / 10 / 20 / 40 s apart, each with a 60 s read
 * timeout: a node whose DNS or network is not back yet (CoreDNS restarting, a reset connection)
 * gets the file a minute later instead of failing the install. A partial file is removed before
 * every try and after the last. Every failed try is said in the pod's log.
 */
final class NodeDownload
{
    public const TRIES = 5;

    public static function script(): string
    {
        $tries = self::TRIES;

        return <<<BASH
# download <url> <file>: wget, \${DOWNLOAD_TRIES:-{$tries}} tries with backoff (NodeDownload)
download() {
  DL_N=1
  DL_W=5
  while :; do
    rm -f "\$2"
    if wget -q -T 60 -O "\$2" "\$1"; then return 0; fi
    if [ "\$DL_N" -ge "\${DOWNLOAD_TRIES:-{$tries}}" ]; then
      rm -f "\$2"
      echo "download of \$1 failed (try \$DL_N of \${DOWNLOAD_TRIES:-{$tries}}), giving up"
      return 1
    fi
    echo "download of \$1 failed (try \$DL_N of \${DOWNLOAD_TRIES:-{$tries}}), next try in \${DL_W}s"
    sleep "\$DL_W"
    DL_N=\$((DL_N + 1))
    DL_W=\$((DL_W * 2))
  done
}

BASH;
    }
}
