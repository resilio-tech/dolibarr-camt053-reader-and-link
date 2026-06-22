<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       class/ZulipNotifier.class.php
 * \ingroup    camt053readerandlink
 * \brief      Send messages to Zulip using the Bot API (stream/topic).
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

/**
 * Class ZulipNotifier
 *
 * Posts a message to a Zulip stream/topic via a bot account
 * (POST {site}/api/v1/messages with HTTP basic auth bot-email:api-key).
 */
class ZulipNotifier
{
	/** @var string Zulip site base URL (e.g. https://org.zulipchat.com) */
	private $site;

	/** @var string Bot email */
	private $botEmail;

	/** @var string Bot API key */
	private $apiKey;

	/** @var string|null Last error message */
	private $error;

	/**
	 * Constructor
	 *
	 * @param string $site     Zulip site base URL
	 * @param string $botEmail Bot email
	 * @param string $apiKey   Bot API key
	 */
	public function __construct(string $site, string $botEmail, string $apiKey)
	{
		$this->site = rtrim($site, '/');
		$this->botEmail = $botEmail;
		$this->apiKey = $apiKey;
	}

	/**
	 * Build a notifier from the module global configuration, or null if incomplete.
	 *
	 * @return ZulipNotifier|null
	 */
	public static function fromConf(): ?ZulipNotifier
	{
		$site = getDolGlobalString('CAMT053_ZULIP_SITE');
		$email = getDolGlobalString('CAMT053_ZULIP_BOT_EMAIL');
		$key = getDolGlobalString('CAMT053_ZULIP_BOT_APIKEY');

		if (empty($site) || empty($email) || empty($key)) {
			return null;
		}

		// The API key is stored encrypted (see admin/setup.php).
		$key = dolDecrypt($key);

		return new self($site, $email, $key);
	}

	/**
	 * Whether a notifier is fully configured.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool
	{
		return !empty($this->site) && !empty($this->botEmail) && !empty($this->apiKey);
	}

	/**
	 * Send a message to a stream/topic.
	 *
	 * @param string $stream  Target stream name
	 * @param string $topic   Target topic
	 * @param string $content Message content (Zulip Markdown)
	 * @return bool True on success
	 */
	public function sendStream(string $stream, string $topic, string $content): bool
	{
		$this->error = null;

		if (!$this->isConfigured()) {
			$this->error = 'Zulip notifier is not configured';
			return false;
		}
		if (empty($stream)) {
			$this->error = 'Zulip stream is empty';
			return false;
		}
		if (!function_exists('curl_init')) {
			$this->error = 'PHP curl extension is required to notify Zulip';
			return false;
		}

		$payload = http_build_query(array(
			'type' => 'stream',
			'to' => $stream,
			'topic' => $topic !== '' ? $topic : 'CAMT.053',
			'content' => $content,
		));

		$ch = curl_init($this->site . '/api/v1/messages');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_USERPWD, $this->botEmail . ':' . $this->apiKey);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

		$response = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($response === false) {
			$this->error = 'Zulip request failed: ' . curl_error($ch);
			curl_close($ch);
			return false;
		}
		curl_close($ch);

		$decoded = json_decode($response, true);
		if ($httpCode !== 200 || !is_array($decoded) || ($decoded['result'] ?? '') !== 'success') {
			$msg = is_array($decoded) && isset($decoded['msg']) ? $decoded['msg'] : ('HTTP ' . $httpCode);
			$this->error = 'Zulip API error: ' . $msg;
			dol_syslog('CAMT053 cron: Zulip API error - ' . $this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Get last error message.
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}
}
