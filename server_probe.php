<?php


$TOKEN = 'x81T6KnOXmkZzFa9_E';


/*
 * 填真正运行网站的PHP版本。
 */
$PHP_MARKERS = array(

    '/www/server/php/82/'
);


/*
 * AI诊断使用。
 *
 * 不存在的日志不会影响基础监控。
 */
$LOG_FILES = array(

    'nginx_error' =>
        '/www/wwwlogs/nginx_error.log',

    'nginx_access' =>
        '/www/wwwlogs/nginx_site_error.log',

    'php_slow' =>
        '/www/server/php/82/var/log/slow.log',

    'mysql_slow' =>
        '/www/server/data/mysql-slow.log'
);


$MAX_LOG_LINES = 80;

$MAX_LOG_BYTES = 32768;


ini_set(
    'display_errors',
    '0'
);

ini_set(
    'log_errors',
    '0'
);

ini_set(
    'memory_limit',
    '32M'
);

@set_time_limit(
    5
);


header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store'
);

header(
    'X-Content-Type-Options: nosniff'
);


function json_fail(
    $code,
    $message
)
{

    http_response_code(
        $code
    );


    echo json_encode(

        array(

            'ok' =>
                false,

            'error' =>
                $message
        ),

        JSON_UNESCAPED_UNICODE
        |
        JSON_UNESCAPED_SLASHES
    );


    exit;
}


$clientToken = isset(
    $_SERVER[
        'HTTP_X_SERVERGUARD_TOKEN'
    ]
)

?

(string)
$_SERVER[
    'HTTP_X_SERVERGUARD_TOKEN'
]

:

'';


if (
    $clientToken === ''

    ||

    !hash_equals(
        (string)$TOKEN,
        $clientToken
    )
) {

    json_fail(
        403,
        'forbidden'
    );
}


$mode = isset(
    $_GET[
        'mode'
    ]
)

?

(string)
$_GET[
    'mode'
]

:

'status';


if (
    $mode !== 'status'

    &&

    $mode !== 'diag'
) {

    json_fail(
        400,
        'bad mode'
    );
}


function read_cpu_stat()
{

    $lines = @file(
        '/proc/stat',
        FILE_IGNORE_NEW_LINES
    );


    if (
        !$lines

        ||

        !isset(
            $lines[0]
        )
    ) {

        return array(
            0,
            0
        );
    }


    $parts = preg_split(

        '/\s+/',

        trim(
            $lines[0]
        )
    );


    array_shift(
        $parts
    );


    $values = array_map(
        'intval',
        $parts
    );


    $idle =

        (
            isset(
                $values[3]
            )
            ?
            $values[3]
            :
            0
        )

        +

        (
            isset(
                $values[4]
            )
            ?
            $values[4]
            :
            0
        );


    return array(

        array_sum(
            $values
        ),

        $idle
    );
}


function cpu_percent()
{

    list(
        $t1,
        $i1
    ) = read_cpu_stat();


    usleep(
        100000
    );


    list(
        $t2,
        $i2
    ) = read_cpu_stat();


    $dt =
        $t2
        -
        $t1;


    $di =
        $i2
        -
        $i1;


    if (
        $dt <= 0
    ) {

        return 0.0;
    }


    return round(

        (
            1
            -
            (
                $di
                /
                $dt
            )
        )

        *

        100,

        1
    );
}


function cpu_cores()
{

    $text = @file_get_contents(
        '/proc/cpuinfo'
    );


    if (
        $text === false
    ) {

        return 1;
    }


    preg_match_all(

        '/^processor\s*:/m',

        $text,

        $m
    );


    return max(
        1,
        count(
            $m[0]
        )
    );
}


function memory_percent()
{

    $lines = @file(
        '/proc/meminfo',
        FILE_IGNORE_NEW_LINES
    );


    if (
        !$lines
    ) {

        return 0.0;
    }


    $data = array();


    foreach (
        $lines
        as
        $line
    ) {

        if (
            preg_match(
                '/^(\w+):\s+(\d+)/',
                $line,
                $m
            )
        ) {

            $data[
                $m[1]
            ] = (
                int
            )$m[2];
        }
    }


    $total = isset(
        $data[
            'MemTotal'
        ]
    )

    ?

    $data[
        'MemTotal'
    ]

    :

    0;


    $available = isset(
        $data[
            'MemAvailable'
        ]
    )

    ?

    $data[
        'MemAvailable'
    ]

    :

    0;


    if (
        $total <= 0
    ) {

        return 0.0;
    }


    return round(

        (
            (
                $total
                -
                $available
            )

            /

            $total
        )

        *

        100,

        1
    );
}


function disk_percent()
{

    $path = is_dir(
        '/www'
    )
    ?
    '/www'
    :
    '/';


    $total = @disk_total_space(
        $path
    );


    $free = @disk_free_space(
        $path
    );


    if (
        $total === false

        ||

        $free === false

        ||

        $total <= 0
    ) {

        return 0.0;
    }


    return round(

        (
            (
                $total
                -
                $free
            )

            /

            $total
        )

        *

        100,

        1
    );
}


function service_status(
    $phpMarkers
)
{

    $nginx = false;

    $mysql = false;

    $php = false;


    $files = glob(
        '/proc/[0-9]*/comm'
    );


    if (
        !$files
    ) {

        return array(

            'nginx' =>
                false,

            'php_fpm' =>
                false,

            'mysql' =>
                false
        );
    }


    foreach (
        $files
        as
        $commFile
    ) {

        $comm = trim(

            (string)
            @file_get_contents(
                $commFile
            )
        );


        if (
            $comm === 'nginx'
        ) {

            $nginx = true;
        }


        if (
            $comm === 'mysqld'

            ||

            $comm === 'mariadbd'
        ) {

            $mysql = true;
        }


        if (
            strpos(
                $comm,
                'php-fpm'
            )
            !==
            false
        ) {

            $pidDir = dirname(
                $commFile
            );


            $cmdline = (
                string
            )
            @file_get_contents(

                $pidDir
                .
                '/cmdline'
            );


            foreach (
                $phpMarkers
                as
                $marker
            ) {

                if (
                    $marker !== ''

                    &&

                    strpos(
                        $cmdline,
                        $marker
                    )
                    !==
                    false
                ) {

                    $php = true;

                    break;
                }
            }
        }


        if (
            $nginx

            &&

            $mysql

            &&

            $php
        ) {

            break;
        }
    }


    return array(

        'nginx' =>
            $nginx,

        'php_fpm' =>
            $php,

        'mysql' =>
            $mysql
    );
}


function process_summary()
{

    $counts = array();

    $top = array();


    $files = glob(
        '/proc/[0-9]*/status'
    );


    if (
        !$files
    ) {

        return array(

            'counts' =>
                array(),

            'top_rss' =>
                array()
        );
    }


    foreach (
        $files
        as
        $statusFile
    ) {

        $text = @file_get_contents(
            $statusFile
        );


        if (
            $text === false
        ) {

            continue;
        }


        $name = 'unknown';

        $rssKb = 0;


        if (
            preg_match(
                '/^Name:\s+(.+)$/m',
                $text,
                $m
            )
        ) {

            $name = trim(
                $m[1]
            );
        }


        if (
            preg_match(
                '/^VmRSS:\s+(\d+)\s+kB$/m',
                $text,
                $m
            )
        ) {

            $rssKb = (
                int
            )$m[1];
        }


        if (
            !isset(
                $counts[
                    $name
                ]
            )
        ) {

            $counts[
                $name
            ] = 0;
        }


        $counts[
            $name
        ]++;


        if (
            $rssKb > 0
        ) {

            $top[] = array(

                'name' =>
                    $name,

                'rss_mb' =>
                    round(
                        $rssKb / 1024,
                        1
                    )
            );
        }
    }


    arsort(
        $counts
    );


    usort(

        $top,

        function (
            $a,
            $b
        ) {

            if (
                $a[
                    'rss_mb'
                ]
                ==
                $b[
                    'rss_mb'
                ]
            ) {

                return 0;
            }


            return (

                $a[
                    'rss_mb'
                ]

                <

                $b[
                    'rss_mb'
                ]

            )

            ?

            1

            :

            -1;
        }
    );


    return array(

        'counts' =>
            array_slice(
                $counts,
                0,
                15,
                true
            ),

        'top_rss' =>
            array_slice(
                $top,
                0,
                10
            )
    );
}


function tcp_summary()
{

    $established = 0;

    $timeWait = 0;


    $paths = array(

        '/proc/net/tcp',

        '/proc/net/tcp6'
    );


    foreach (
        $paths
        as
        $path
    ) {

        $lines = @file(

            $path,

            FILE_IGNORE_NEW_LINES
            |
            FILE_SKIP_EMPTY_LINES
        );


        if (
            !$lines
        ) {

            continue;
        }


        array_shift(
            $lines
        );


        foreach (
            $lines
            as
            $line
        ) {

            $p = preg_split(

                '/\s+/',

                trim(
                    $line
                )
            );


            $state = isset(
                $p[3]
            )

            ?

            $p[3]

            :

            '';


            if (
                $state === '01'
            ) {

                $established++;
            }


            elseif (
                $state === '06'
            ) {

                $timeWait++;
            }
        }
    }


    return array(

        'established' =>
            $established,

        'time_wait' =>
            $timeWait
    );
}


function tail_safe(
    $path,
    $maxLines,
    $maxBytes
)
{

    if (
        !is_file(
            $path
        )
    ) {

        return (
            '[UNAVAILABLE] file not found'
        );
    }


    if (
        !is_readable(
            $path
        )
    ) {

        return (
            '[UNAVAILABLE] permission denied'
        );
    }


    $size = @filesize(
        $path
    );


    if (
        $size === false

        ||

        $size <= 0
    ) {

        return '';
    }


    $fh = @fopen(
        $path,
        'rb'
    );


    if (
        !$fh
    ) {

        return (
            '[UNAVAILABLE] open failed'
        );
    }


    $readBytes = min(

        (
            int
        )$size,

        (
            int
        )$maxBytes
    );


    if (
        $readBytes
        <
        $size
    ) {

        @fseek(

            $fh,

            -$readBytes,

            SEEK_END
        );
    }


    $data = @fread(

        $fh,

        $readBytes
    );


    @fclose(
        $fh
    );


    if (
        $data === false
    ) {

        return (
            '[UNAVAILABLE] read failed'
        );
    }


    $lines = preg_split(

        '/\r\n|\n|\r/',

        $data
    );


    if (
        $size
        >
        $readBytes

        &&

        count(
            $lines
        )
        >
        1
    ) {

        array_shift(
            $lines
        );
    }


    return implode(

        "\n",

        array_slice(
            $lines,
            -$maxLines
        )
    );
}


$load = @sys_getloadavg();


if (
    !is_array(
        $load
    )
) {

    $load = array(
        0,
        0,
        0
    );
}


$result = array(

    'ok' =>
        true,

    'time' =>
        gmdate(
            'c'
        ),

    'hostname' =>
        gethostname()
        ?
        gethostname()
        :
        '',


    'metrics' => array(

        'cpu' =>
            cpu_percent(),

        'cores' =>
            cpu_cores(),

        'memory' =>
            memory_percent(),

        'load1' =>
            round(
                (
                    float
                )
                (
                    isset(
                        $load[0]
                    )
                    ?
                    $load[0]
                    :
                    0
                ),
                2
            ),

        'load5' =>
            round(
                (
                    float
                )
                (
                    isset(
                        $load[1]
                    )
                    ?
                    $load[1]
                    :
                    0
                ),
                2
            ),

        'load15' =>
            round(
                (
                    float
                )
                (
                    isset(
                        $load[2]
                    )
                    ?
                    $load[2]
                    :
                    0
                ),
                2
            ),

        'disk' =>
            disk_percent()
    ),


    'services' =>
        service_status(
            $PHP_MARKERS
        )
);


if (
    $mode === 'diag'
) {

    $logs = array();


    foreach (
        $LOG_FILES
        as
        $name
        =>
        $path
    ) {

        $logs[
            $name
        ] = tail_safe(

            (
                string
            )$path,

            $MAX_LOG_LINES,

            $MAX_LOG_BYTES
        );
    }


    $result[
        'diagnostics'
    ] = array(

        'processes' =>
            process_summary(),

        'tcp' =>
            tcp_summary(),

        'logs' =>
            $logs
    );
}


echo json_encode(

    $result,

    JSON_UNESCAPED_UNICODE
    |
    JSON_UNESCAPED_SLASHES
);