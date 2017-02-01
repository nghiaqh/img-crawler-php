<?php

require_once(__DIR__.'/include/php-websockets/websockets.php');
require_once(__DIR__.'/image-crawler.php');

class CrawlerServer extends WebsocketServer {
	private $crawler;

	public function __construct($addr, $port, $bufferLength = 2048) {
		// generate cookie for some site
		$this->cookie = getCookie();
		$this->crawler = new ImageCrawler;
		$this->sizelimit = 30720;
		$this->preprocess = null;

		// call WebsocketServer constructor
		parent::__construct($addr, $port, $bufferLength);
	}

	/**
	 * Main logic: parsing input pages array from message and crawl images
	 * @param  [type] $user    [description]
	 * @param  [String] $message [description]
	 * @return [type]          [description]
	 */
	protected function process ($user, $message) {
		if ($message) {
			$pages = explode(',', $message);
			if (empty($pages)) {
				$reply = json_encode(array(
					'type' => 'error',
					'message' => 'Empty input!'
				));
				$this->send($user, $reply);
				return 0;
			}

			foreach ($pages as $pid=>$page) {
				$page = trim($page);
				$thumbnailContainerId = null;
				$imageContainerId = null;
				$this->preprocess = null;

				// Generate parameters for crawler
				if (strpos($page, 'hentairules.net') !== false) {
					$thumbnailContainerId = 'thumbnails';
					$imageContainerId = 'theImage';
					$this->preprocess = 'getOriginSizeImageUrl';
				} else if (strpos($page, 'g.e-hentai.org') !== false || strpos($message, 'exhentai.org') !== false) {
					$thumbnailContainerId = 'gdt';
					$imageContainerId = 'img';
					$this->cookie = $this->cookie . '; nw=1; uconfig=dm_t;';
				} else if (strpos($page, 'nhentai.net') !== false) {
					$thumbnailContainerId = 'thumbnail-container';
					$imageContainerId = 'image-container';
				}

				$this->stdout('Crawling ' . $page); // Terminal log

				$array = $this->crawler->scanForImageLinks($page, $thumbnailContainerId, $this->cookie);
				$urls = $array[1];
				$title = substr($array[2], 0, 164); //cut part of title longer than 300 characters

				// Send to client currently-being-processed page info.
				$reply = json_encode(array(
					'type' => 'page',
					'id' => $pid,
					'url' => $page,
					'title' => $title,
					'images' => $urls,
					'progress' => 0
				));
				$this->send($user, $reply);

				// Scan image pages for actual image
				foreach ($urls as $i=>$u) {
					if (substr($u, -4) === '.jpg' || substr($u, -4) === '.png') {
						$this->downloadImage($u, $title, $i, $user);
					} else {
						foreach ($this->crawler->scanForImages($u, $imageContainerId, $this->cookie) as $image) {
							$this->downloadImage($image, $title, $i, $user);
						}
					}
				}
			}
		}

		$this->send($user, 'Completed crawling!');
		$this->stdout('Completed crawling!');
	}

	protected function downloadImage($image, $title, $i, $user) {
		if ($this->preprocess) {
			$image = call_user_func($this->preprocess, $image);
		}

		// download images have size > size limit
		if ($this->crawler->curlGetFileSize($image, $this->cookie) > $this->sizelimit) {
			$reply = json_encode(array(
				'type' => 'image',
				'id' => $i,
				'url' => $image,
				'progress' => 0
			));
			$this->send($user, $reply);
			$this->crawler->downloadImage($image, $title, $this->cookie, array($this, 'progress'));
		}
	}

	protected function connected ($user) {
		$this->send($user, 'Connection established to 127.0.0.1:9001');
		$this->user = $user;
	}

	protected function closed ($user) {
		// Do nothing as we have no cleanup to do.
	}

	// report download progress
	public function progress($resource, $download_size, $downloaded, $upload_size, $uploaded) {
		if ($download_size > 0) {
			$reply = json_encode(array(
				'type' => 'image progress',
				'progress' => $downloaded / $download_size  * 100
			));
			$this->send($this->user, $reply);
		}
	}
}

// $server = new CrawlerServer('0.0.0.0', '9001');
//
// try {
// 	$server->run();
// } catch (Exception $e) {
// 	$server->stdout($e->getMessage());
// }
