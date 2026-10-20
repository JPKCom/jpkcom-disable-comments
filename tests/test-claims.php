<?php
/**
 * Re-measures the suite statistics the repository states about itself.
 *
 * CLAUDE.md (section "Tests") and the header of tests/test-hooks.php state how
 * many cases the suite has and how it fares against earlier releases. Those
 * figures have been wrong twice because nobody re-ran them. This file runs the
 * suite and compares every figure with the measurement:
 *
 * - "N cases."                                    total of the current suite;
 * - "the N gate-behaviour cases"                  cases that only run when a
 *                                                 rest_pre_dispatch gate is registered;
 * - "against X.Y.Z, R of the N cases run and F fail"
 *                                                 the suite run against the files of
 *                                                 tag vX.Y.Z (skipped locally when the
 *                                                 tag is not in the clone; a failure in
 *                                                 CI, whose checkout fetches the tags).
 *
 * Any other number in those two places fails (code, version numbers and the
 * HTTP status 404 aside): a figure is either checked here or not written down.
 *
 * @package JPKCom_Disable_Comments
 * @since 1.0.11
 */

declare(strict_types=1);

$root = dirname( path: __DIR__ );

$failed  = 0;
$passed  = 0;
$skipped = 0;

/*
 * In CI (GitHub Actions sets CI=true) a missing tag fails instead of being
 * skipped: a skip there would leave the release figures unchecked everywhere.
 */
$in_ci = getenv( 'CI' ) === 'true';

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_claims_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

/**
 * Report a check that cannot run here.
 *
 * @param string $name   Case name.
 * @param string $reason Why it was skipped.
 * @return void
 */
function jpkcom_claims_skip( string $name, string $reason ): void {
    global $skipped;

    ++$skipped;
    printf( "  skip %s -- %s\n", $name, $reason );
}

/**
 * Run a suite file in a separate PHP process and read its summary line.
 *
 * @param string $file Path of the PHP file to run.
 * @return array{run:int,failed:int}|null Null when no summary was printed (fatal error).
 */
function jpkcom_claims_run( string $file ): ?array {
    $output = array();
    exec( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=-1 ' . escapeshellarg( $file ) . ' 2>&1', $output );

    if ( preg_match( '/(\d+) passed, (\d+) failed\s*$/', implode( "\n", $output ), $m ) !== 1 ) {
        return null;
    }

    return array(
        'run'    => (int) $m[1] + (int) $m[2],
        'failed' => (int) $m[2],
    );
}

/**
 * Remove a directory tree created by this file.
 *
 * @param string $dir Directory.
 * @return void
 */
function jpkcom_claims_rmtree( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $items as $item ) {
        $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
    }

    rmdir( $dir );
}

echo "jpkcom-disable-comments: stated suite statistics\n";

$tmp = sys_get_temp_dir() . '/jpkcom-dc-claims-' . getmypid();
jpkcom_claims_rmtree( $tmp );
mkdir( $tmp, 0700, true );

$suite   = $root . '/tests/test-hooks.php';
$current = jpkcom_claims_run( $suite );

jpkcom_claims_check( 'the current suite runs to its summary line', $current !== null );

if ( $current === null ) {
    jpkcom_claims_rmtree( $tmp );
    printf( "\n  %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped );
    exit( 1 );
}

$total = $current['run'];

/*
 * The same suite with the rest_pre_dispatch registration dropped: every case
 * that no longer runs belongs to the gate behaviour.
 */
$harness = $tmp . '/without-gate.php';
file_put_contents(
    $harness,
    '<?php' . "\n"
    . 'declare(strict_types=1);' . "\n"
    . 'function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {' . "\n"
    . '    if ( $hook === \'rest_pre_dispatch\' ) { return; }' . "\n"
    . '    $GLOBALS[\'jpkcom_hooks\'][\'filter\'][ $hook ][] = array( $callback, $priority, $accepted_args );' . "\n"
    . '}' . "\n"
    . 'require ' . var_export( $suite, true ) . ';' . "\n"
);
$without_gate = jpkcom_claims_run( $harness );
$gate_cases   = $without_gate === null ? null : $total - $without_gate['run'];

/** Where figures are stated: label => text. */
$places = array();

$doc = (string) file_get_contents( $root . '/CLAUDE.md' );
if ( preg_match( '/\n## Tests\n(.*?)(?:\n---\n|$)/s', $doc, $m ) === 1 ) {
    $places['CLAUDE.md, Tests'] = $m[1];
}
jpkcom_claims_check( 'CLAUDE.md has a Tests section', isset( $places['CLAUDE.md, Tests'] ) );

if ( preg_match( '#/\*\*.*?\*/#s', (string) file_get_contents( $suite ), $m ) === 1 ) {
    $places['tests/test-hooks.php header'] = $m[0];
}

$measured_versions = array();

foreach ( $places as $place => $text ) {
    $rest = preg_replace( '/\s+/', ' ', $text );

    /* Total. */
    if ( preg_match_all( '/\b(\d+) cases\./', $rest, $all, PREG_SET_ORDER ) > 0 ) {
        foreach ( $all as $claim ) {
            jpkcom_claims_check(
                sprintf( '%s: "%s" matches the suite', $place, $claim[0] ),
                (int) $claim[1] === $total,
                sprintf( 'the suite has %d cases', $total )
            );
        }
        $rest = preg_replace( '/\b\d+ cases\./', '', $rest );
    }

    /* Gate-behaviour cases. */
    if ( preg_match_all( '/\bthe (\d+) gate-behaviour cases\b/', $rest, $all, PREG_SET_ORDER ) > 0 ) {
        foreach ( $all as $claim ) {
            jpkcom_claims_check(
                sprintf( '%s: "%s" matches the suite', $place, $claim[0] ),
                $gate_cases !== null && (int) $claim[1] === $gate_cases,
                $gate_cases === null ? 'the suite without a gate did not finish' : sprintf( 'measured %d', $gate_cases )
            );
        }
        $rest = preg_replace( '/\bthe \d+ gate-behaviour cases\b/', '', $rest );
    }

    /* Earlier releases. */
    $pattern = '/\bagainst (\d+\.\d+\.\d+), (\d+) of the (\d+) cases run and (\d+) fail\b/';
    if ( preg_match_all( $pattern, $rest, $all, PREG_SET_ORDER ) > 0 ) {
        foreach ( $all as $claim ) {
            [ $sentence, $version, $run, $of, $fail ] = $claim;
            $name = sprintf( '%s: "%s"', $place, $sentence );

            if ( (int) $of !== $total ) {
                jpkcom_claims_check( $name, false, sprintf( 'the suite has %d cases, not %s', $total, $of ) );
                continue;
            }

            if ( ! array_key_exists( $version, $measured_versions ) ) {
                $tag   = 'v' . $version;
                $probe = array();
                exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse --verify --quiet ' . escapeshellarg( $tag . '^{commit}' ) . ' 2>/dev/null', $probe, $code );

                if ( $code !== 0 ) {
                    $measured_versions[ $version ] = false;
                } else {
                    $tree = $tmp . '/' . $version;
                    mkdir( $tree . '/tests', 0700, true );
                    exec(
                        'git -C ' . escapeshellarg( $root ) . ' archive ' . escapeshellarg( $tag )
                        . ' jpkcom-disable-comments.php includes | tar -x -C ' . escapeshellarg( $tree ) . ' 2>&1',
                        $probe,
                        $code
                    );
                    copy( $suite, $tree . '/tests/test-hooks.php' );
                    $measured_versions[ $version ] = $code === 0 ? jpkcom_claims_run( $tree . '/tests/test-hooks.php' ) : null;
                }
            }

            $measured = $measured_versions[ $version ];

            if ( $measured === false ) {
                if ( $in_ci ) {
                    jpkcom_claims_check( $name, false, sprintf( 'tag v%s is not in this clone; CI must check out the tags', $version ) );
                } else {
                    jpkcom_claims_skip( $name, sprintf( 'tag v%s is not in this clone', $version ) );
                }
                continue;
            }

            jpkcom_claims_check(
                $name,
                $measured !== null && $measured['run'] === (int) $run && $measured['failed'] === (int) $fail,
                $measured === null ? 'the suite did not finish' : sprintf( 'measured %d run, %d fail', $measured['run'], $measured['failed'] )
            );
        }
        $rest = preg_replace( $pattern, '', $rest );
    }

    /*
     * Whatever number is left was not checked above. Code, version numbers and
     * the HTTP status 404 are not figures about the suite.
     */
    $rest      = preg_replace( array( '/```.*?```/s', '/`[^`]*`/', '/\b\d+(?:\.\d+)+\b/', '/\b404\b/' ), ' ', $rest );
    $unchecked = preg_match_all( '/(?:\S+\s+)?\b\d+\b(?:\s+\S+)?/', $rest, $left );
    jpkcom_claims_check(
        sprintf( '%s: no number outside the checked forms', $place ),
        $unchecked === 0,
        $unchecked === 0 ? '' : 'unchecked: "' . implode( '", "', $left[0] ) . '"'
    );
}

/*
 * The release comparisons need the tags. CI must check them out, otherwise the
 * figures about earlier releases are never re-measured there.
 */
$ci_workflow = (string) file_get_contents( $root . '/.github/workflows/ci.yml' );
jpkcom_claims_check(
    'ci.yml: the checkout fetches the full history with its tags (fetch-depth: 0)',
    preg_match( '/uses:\s*actions\/checkout@\S+[^\n]*\n\s+with:\s*\n(?:[ \t]+(?:#|[\w-]+:)[^\n]*\n)*?[ \t]+fetch-depth:\s*0\s*(?:#[^\n]*)?\n/', $ci_workflow ) === 1
);

jpkcom_claims_rmtree( $tmp );

printf( "\n  %d passed, %d failed, %d skipped\n", $passed, $failed, $skipped );

exit( $failed > 0 ? 1 : 0 );
