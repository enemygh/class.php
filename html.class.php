<?php
// include 'html.class.php';
// $html = new html('http://');
// $nth = $html -> query('a');
// $arr = $html -> attr('href');

class html {
	public $url;
	public $nth;
	public $document;
	public $nodes = null;
	
	function __construct ($url, $data = null) {
		if ($data) {
			$opts = array(
				'http'=> array(
					'method' => 'POST',
					'header' => 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
					'content' => $data
				)
			);
		} else {
			$opts = array(
				'http'=> array(
					'method' => 'GET',
					'header' => "Accept-Language: zh-CN,zh\r\n" .
								"User-Agent: $_SERVER[HTTP_USER_AGENT]\r\n"
				)
			);
		}
		$context = stream_context_create($opts);
		$file = file_get_contents($url, false, $context);
		$this -> document = $file;
		$this -> url = $url;
		
		// $fp = fopen($url, 'rb', false, stream_context_create($opts));
		// $file = stream_get_contents($fp);
		// $arr = stream_get_meta_data($fp);
		// fclose($fp);
	}
	
	function html2dom() {
		$doc = $this -> document;
		if (preg_match('/<meta[^>]+\bcharset="?([^"]+)"/', $doc, $m) && $m[1] != 'utf-8')
			$doc = iconv($m[1], 'UTF-8//IGNORE', $doc);
		$this -> document =
			$doc = preg_replace(['/(\n|\r)/', '/<!--(.*?)-->/', '/\s+/'], ' ', $doc);
		
		$script = false;
		$p = 0; $i = 0; $j = 0;
		$nodes = array(['tag' => 'root']);
		while (preg_match('/<(\/?)([a-z]\w*)[^>]*>()/', $doc, $m, PREG_OFFSET_CAPTURE, $p)) {
			if ($script && (!$m[1][0] || $m[2][0] !== 'script') || $m[2][0] === 'br' || $m[2][0] === 'hr') {
				$p = $m[3][1];
				continue;
			}
			if ($m[1][0]) {
				if ($script && $m[2][0] === 'script')
					$script = false;
				$nodes[$i]['finish'] = $p = $m[3][1];
				$i = $nodes[$i]['parent'];
				continue;
			}
			
			$nodes[$i]['children'][] = ++$j;
			$nodes[] = array(
				'tag'=> $m[2][0],
				'parent' => $i,
				'start' => $m[0][1]
			);
			if (preg_match_all('/\s([^=]+)="([^"]+)"/', $m[0][0], $matches))
				$nodes[$j]['attr'] = array_combine($matches[1], $matches[2]);
			
			$p = $m[3][1];
			switch ($m[2][0]) {
				case 'base': case 'link': case 'meta':
				case 'img': case 'area': case 'input': case 'embed':
					$nodes[$j]['finish'] = $m[3][1];
					break;
				case 'script':
					$script = true;
				default:
					$i = $j;
			}
		}
		$this -> nodes = $nodes;
		return $nodes;
	}
	
	function query($str) {
		$nodes = $this -> nodes ?: $this -> html2dom();
		$nth = range(1, count($nodes));
		preg_match_all('/(?:(?:\s|>|\+|~)|(?:[^\s>\+~]+))/', $str, $m);
		foreach ($m[0] as $v) {
			$arr = array();
			switch ($v) {
				case ' ':
					foreach ($nth as $n) {
						$i = $n;
						while (isset($nodes[$i]['children'])) {
							$c = $nodes[$i]['children'];
							$i = $c[count($c) - 1];
						}
						if ($i > $n)
							$arr = array_merge($arr, range($n + 1, $i));
					}
					break;
				case '>':
					foreach ($nth as $n) {
						if (isset($nodes[$n]['children']))
							$arr = array_merge($arr, $nodes[$n]['children']);
					}
					break;
				case '+':
					foreach ($nth as $n) {
						if ($nodes[$n + 1]['parent'] === $nodes[$n]['parent'])
							$arr[] = $n + 1;
					}
					break;
				case '~':
					foreach ($nth as $n) {
						$c = $nodes[$nodes[$n]['parent']]['children'];
						$arr = array_merge($arr, array_slice($c, array_search($n, $c) + 1));
					}
					break;
				default:
					preg_match('/([a-z]\w*)?(?:#((?:\w|-)+))?((?:\.(?:\w|-)+)+)?((?:\[(?:\w|-)+]?)+)?(?::(-?\d+))?/', $v, $q);
					foreach ($nth as $n) {
						if (!empty($q[2]))
							if ($q[2] === $nodes[$n]['attr']['id']) {
								$arr[] = $n;
								break;
							} else
								continue;
						if (!empty($q[1]) && $q[1] !== $nodes[$n]['tag'])
							continue;
						if (!empty($q[3])) {
							$c = explode('.', substr($q[3], 1));
							if ($c !== array_intersect($c, explode(' ', $nodes[$n]['attr']['class'])))
								continue;
						}
						if (!empty($q[4])) {
							foreach (explode('[', substr(strtr($q[4], [']' => '']), 1)) as $v) {
								if (!isset($nodes[$n]['attr'][$v]))
									continue 2;
							}
						}
						$arr[] = $n;
					}
					if ($q[5])
						$arr = array_slice($arr, $q[5], 1);
			}
			if (empty($arr))
				return $arr;
			$nth = $arr;
		}
		$this -> nth = $nth;
		return $nth;
	}
	
	function attr ($str) {
		$nth = $this -> nth;
		$nod = $this -> nodes;
		$doc = $this -> document;
		$arr = array();
		switch ($str) {
			case 'html':
				foreach ($nth as $n) {
					$arr[] = substr($doc, $nod[$n]['start'], $nod[$n]['finish'] - $nod[$n]['start']);
				}
				break;
			case 'text':
				foreach ($nth as $n) {
					$t = substr($doc, $nod[$n]['start'], $nod[$n]['finish'] - $nod[$n]['start']);
					$arr[] = strip_tags($t, '<br>');
				}
				break;
			default:
				foreach ($nth as $n) {
					$arr[] = $nod[$n]['attr'][$str];
				}
		}
		return $arr;
	}
	
	function href ($str) {
		$url = $this -> url;
		$nth = $this -> nth;
		$nodes = $this -> nodes;
		$arr = array();
		$u = preg_filter('/.*(\/{2}[^\/]+).*/', '\1', $url);
		$uu = preg_filter('/(?:^[^\/]*|[^\/]*$)/', '', $url);
		foreach ($nth as $n) {
			$a = $nodes[$n]['attr'][$str];
			if (substr($a, 0, 2) === '//' || substr($a, 0, 7) === 'http://' || substr($a, 0, 8) === 'https://') {
				$arr[] = $a;
				continue;
			}
			if (substr($a, 0, 1) === '/') {
				$arr[] = $u . $a;
				continue;
			}
			$arr[] = $uu . $a;
		}
		return $arr;
	}
	
}