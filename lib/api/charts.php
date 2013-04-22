<?php

require_once '../../lib/utils/chart_publish.php';

/**
 * API: get list of all charts by the current user
 */
$app->get('/charts', function() use ($app) {
    $user = DatawrapperSession::getUser();
    if ($user->isLoggedIn()) {
        $filter = array();
        if ($app->request()->get('filter')) {
            $f = explode("|", $app->request()->get('filter'));
            foreach ($f as $e) {
                list($key, $val) = explode(":", $e);
                $filter[$key] = $val;
            }
        }
        $charts = ChartQuery::create()->getPublicChartsByUser($user, $filter, 0, 200, $app->request()->get('order'));
    } else {
        $charts = ChartQuery::create()->getGuestCharts();
    }
    $res = array();
    foreach ($charts as $chart) {
        $res[] = $app->request()->get('expand') ? $chart->serialize() : $chart->shortArray();
    }
    ok($res);
});

/**
 * API: create a new empty chart
 */
$app->post('/charts', function() {
    $user = DatawrapperSession::getUser();
    try {
        $chart = ChartQuery::create()->createEmptyChart($user);
        $result = array($chart->serialize());
        ok($result);
    } catch (Exception $e) {
        error('create-chart-error', $e->getMessage());
    }
});


/*
 * returns the metadata for all charts that are allowed
 * to show in the gallery
 */
$app->get('/gallery', function() use ($app) {
    $result = array();
    $q = ChartQuery::create()
        ->filterByShowInGallery(true)
        ->orderByCreatedAt('desc');
    if ($app->request()->get('type')) {
        $q->filterByType($app->request()->get('type'));
    }
    if ($app->request()->get('theme')) {
        $q->filterByTheme($app->request()->get('theme'));
    }
    if ($app->request()->get('month')) {
        $q->filterByTheme($app->request()->get('theme'));
    }
    $charts = $q->limit(20)->find();
    foreach ($charts as $chart) {
        $result[] = $chart->toArray();
    }
    ok($result);
});

/**
 * load chart meta data
 *
 * @param id chart id
 */
$app->get('/charts/:id', function($id) use ($app) {
    $chart = ChartQuery::create()->findPK($id);
    if (!empty($chart)) {
        ok($chart->serialize());
    } else {
        error('chart-not-found', 'No chart with that id was found');
    }
});



/**
 * checks if a chart is writeable by the current user (or guest)
 *
 * @param chart_id
 * @param callback the function to be executed if chart is writable
 */
function if_chart_is_writable($chart_id, $callback) {
    $chart = ChartQuery::create()->findPK($chart_id);
    if (!empty($chart)) {
        $user = DatawrapperSession::getUser();
        $res = $chart->isWritable($user);
        if ($res === true) {
            call_user_func($callback, $user, $chart);
        } else {
            error('access-denied', $res);
        }
    } else {
        error('no-such-chart', '');
    }
}


function if_chart_exists($id, $callback) {
    $chart = ChartQuery::create()->findPK($id);
    if ($chart) {
        call_user_func($callback, $chart);
    } else {
        // no such chart
        error('no-such-chart', '');
    }
}

/* check user and update chart meta data */
$app->put('/charts/:id', function($id) use ($app) {
    if_chart_is_writable($id, function($user, $chart) use ($app) {
        $json = json_decode($app->request()->getBody(), true);
        $chart->unserialize($json);
        ok($chart->serialize());
    });
});



/**
 * API: get chart data
 *
 * @param chart_id chart id
 */
$app->get('/charts/:id/data', function($chart_id) use ($app) {
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {
        $data = $chart->loadData();
        $app->response()->header('Content-Type', 'text/csv;charset=utf-8');
        print $data;
    });
});


/**
 * API: upload data to a chart
 *
 * @param chart_id chart id
 */
$app->put('/charts/:id/data', function($chart_id) use ($app) {
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {
        $data = $app->request()->getBody();
        try {
            $filename = $chart->writeData($data);
            $chart->save();
            ok($filename);
        } catch (Exception $e) {
            error('io-error', $e->getMessage());
        }
    });
});

/**
 * API: upload csv file to a chart
 *
 * @param chart_id chart id
 */
$app->post('/charts/:id/data', function($chart_id) use ($app) {
    disable_cache($app);
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {

        require_once '../../lib/utils/file-uploader.php';

        // list of valid extensions, ex. array("jpeg", "xml", "bmp")
        $allowedExtensions = array('txt', 'csv', 'tsv');
        // max file size in bytes
        $sizeLimit = 2 * 1024 * 1024;

        $uploader = new qqFileUploader($allowedExtensions, $sizeLimit);
        $result = $uploader->handleUpload('../../charts/data/tmp/');

        // to pass data through iframe you will need to encode all html tags
        $data = file_get_contents($uploader->filename);

        // check and correct file encoding
        function detect_encoding($string) {
          $list = array('utf-8', 'iso-8859-15', 'iso-8859-1', 'iso-8859-3', 'windows-1251');
          foreach ($list as $item) {
            try {
                $sample = iconv($item, $item, $string);
                if (md5($sample) == md5($string))
                    return $item;
            } catch (Exception $e) {}
          }
          return null;
        }
        $enc = detect_encoding($data); // works better than mb_detect_encoding($data);
        if (strtolower($enc) != "utf-8") {
            $data = mb_convert_encoding($data, "utf-8", $enc);
        }

        try {
            if ($result['success']) {
                $filename = $chart->writeData($data);
                $chart->save();
                //echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);
                unlink($uploader->filename);
                ok($result);
            } else {
                error('upload-error', $result['error']);
            }
        } catch (Exception $e) {
            error('io-error', $e->getMessage());
        }

    });
});



/* delete chart */
$app->delete('/charts/:id', function($id) use ($app) {
    if_chart_is_writable($id, function($user, $chart) use ($app) {
        $chart->setDeleted(true);
        $chart->setLastEditStep(3);
        $chart->save();
        $chart->unpublish();
        ok('');
    });
});

/**
 * API: copy a chart
 *
 * @param chart_id chart id
 */
$app->post('/charts/:id/copy', function($chart_id) use ($app) {
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {
        try {
            $copy = ChartQuery::create()->copyChart($chart);
            $copy->setUser(DatawrapperSession::getUser());
            $copy->save();
            ok(array('id' => $copy->getId()));
        } catch (Exception $e) {
            error('io-error', $e->getMessage());
        }
    });
});


function get_static_path($chart) {
    $static_path = "../../charts/static/" . $chart->getID();
    if (!is_dir($static_path)) {
        mkdir($static_path);
    }
    return $static_path;
}

function download($url, $outf) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = fopen($outf, 'w');

        $strCookie = 'DW-SESSION=' . $_COOKIE['DW-SESSION'] . '; path=/';
        session_write_close();

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0 );
        curl_setopt($ch, CURLOPT_COOKIE, $strCookie);
        if (isset($GLOBALS['dw_config']['http_auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $GLOBALS['dw_config']['http_auth']);
        }
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

    } else {
        $cfg = array(
            'http' => array(
                'header' => 'Connection: close\r\n',
                'method' => 'GET'
            )
        );
        if (isset($GLOBALS['dw_config']['http_auth'])) {
            $cfg['http']['header'] .=
                "Authorization: Basic " . base64_encode($GLOBALS['dw_config']['http_auth']) . '\r\n';
        }
        $context = stream_context_create($cfg);
        $html = file_get_contents($url, false, $context);
        file_put_contents($outf, $html);
    }
}


function _setPublishStatus($chart, $status) {
    if (isset($_GLOBALS['dw-config']['memcache'])) {
        $memcache->set('publish-status-' . $chart->getID(), round($status*100));
    } else {
        file_put_contents('../../charts/tmp/publish-status-' . $chart->getID(), round($status*100));
    }
}

function _getPublishStatus($chart) {
    if (isset($_GLOBALS['dw-config']['memcache'])) {
        return $memcache->get('publish-status-' . $chart->getID());
    } else {
        $fn = '../../charts/tmp/publish-status-' . $chart->getID();
        if (!file_exists($fn)) return false;
        return file_get_contents($fn);
    }
}

function _clearPublishStatus($chart) {
    if (isset($_GLOBALS['dw-config']['memcache'])) {
        global $memcache;
        $memcache->delete('publish-status-' . $chart->getID());
    } else {
        unlink('../../charts/tmp/publish-status-' . $chart->getID());
    }
}


$app->post('/charts/:id/publish', function($chart_id) use ($app) {
    disable_cache($app);
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {

        if (isset($_GLOBALS['dw-config']['memcache'])) {
            $memcfg = $_GLOBALS['dw-config']['memcache'];
            global $memcache;
            $memcache = new Memcache;
            $memcache->connect($memcfg['host'], $memcfg['port']) or die ("Could not connect");
        }

        $files = array();
        _setPublishStatus($chart, 0);

        $files = array_merge($files, publish_html($user, $chart));
        $files = array_merge($files, publish_css($user, $chart));
        $files = array_merge($files, publish_data($user, $chart));
        $files = array_merge($files, publish_js($user, $chart));

        _setPublishStatus($chart, 0.3);

        $totalSize = 0;  // total file size
        foreach ($files as $i => $file) {
            $totalSize += filesize($file[0]);
        }

        $done = 0;
        foreach ($files as $file) {
            publish_push_to_cdn(array($file), $chart);
            $done += filesize($file[0]);
            _setPublishStatus($chart, 0.3 + ($done / $totalSize) * 0.7);
        }

        _setPublishStatus($chart, 1);
        _clearPublishStatus($chart);

        // queue a job for thumbnail generation
        $params = array(
            'width' => $chart->getMetadata('publish.embed-width'),
            'height' => $chart->getMetadata('publish.embed-height')
        );
        $job = JobQuery::create()->createJob("static", $chart, $user, $params);

        ok();

    });
});

$app->get('/charts/:id/publish/status', function($chart_id) use ($app) {
    disable_cache($app);
    if_chart_is_writable($chart_id, function($user, $chart) use ($app) {
        echo _getPublishStatus($chart);
    });
});


/*
 * stores client-side generated chart thumbnail
 */
$app->put('/charts/:id/thumbnail/:thumb', function($chart_id, $thumb) use ($app) {
    disable_cache($app);
    if_chart_is_writable($chart_id, function($user, $chart) use ($app, $thumb) {
        try {
            $imgurl = $app->request()->getBody();
            $imgdata = base64_decode(substr($imgurl, strpos($imgurl, ",") + 1));
            $static_path = get_static_path($chart);
            file_put_contents($static_path . "/" . $thumb . '.png', $imgdata);
            if ($pub = get_module('publish')) {
                $pub->publish(array(array($static_path . "/" . $thumb . '.png', $chart->getID() . '/' . $thumb . '.png', 'image/png')));
            }
            ok();
        } catch (Exception $e) {
            error('io-error', $e->getMessage());
        }
    });
});

/*
 * stores static snapshot of a chart (data, configuration, etc) as JSON
 * to /test/test-charts. This aims to simplify the generation of test
 * cases using the Datawrapper editor. Only for debugging.
 */
$app->post('/charts/:id/store_snapshot', function($chart_id) use ($app) {
    if (!empty($GLOBALS['dw_config']['debug_export_test_cases'])) {
        if_chart_exists($chart_id, function($chart) use ($app) {
            $json = $chart->serialize();
            $payload = json_decode($app->request()->getBody(), true);
            $name = $payload['id'];
            $json['_data'] = $chart->loadData();
            $json['_sig'] = $payload['signature'];
            if (empty($name)) {
                error('', 'no name specified');
            } else {
                $name = str_replace(" ", "-", $name);
                $json['_id'] = $name;
                file_put_contents("../../test/test-charts/" . $name . ".json", json_encode($json));
                ok();
            }
        });
    }
});

