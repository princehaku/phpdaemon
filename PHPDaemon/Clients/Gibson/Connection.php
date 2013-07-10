<?php
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;

class Connection extends ClientConnection {
	public $error; // error message

	const REPL_ERR = 0x00;              // Generic error while executing the query.
	const REPL_ERR_NOT_FOUND = 0x01;    // Specified key was not found.
	const REPL_ERR_NAN = 0x02;          // Expected a number ( TTL or TIME ) but the specified value was invalid.
	const REPL_ERR_MEM = 0x03;          // The server reached configuration memory limit and will not accept any new value until its freeing routine will be executed.
	const REPL_ERR_LOCKED = 0x04;       // The specificed key was locked by a OP_LOCK or a OP_MLOCK query.
	const REPL_OK = 0x05;               // Query succesfully executed, no data follows.
	const REPL_VAL = 0x06;              // Query succesfully executed, value data follows.
	const REPL_KVAL = 0x07;             // Query succesfully executed, multiple key => value data follows.

	const STATE_PACKET_HDR = 0x01;
	const STATE_PACKET_DATA = 0x02;

	/**
	 * Default low mark. Minimum number of bytes in buffer.
	 * @var integer
	 */
//    protected $lowMark = 2;

	protected $arch64 = true;

	public $responseCode;
	public $encoding;
	public $responseLength;
	public $result;
	public $isFinal;
	public $resultTotalNum;
	public $resultReadedNum;

	protected $currentKey;

	protected function onRead() {
		start:
		if ($this->state === static::STATE_STANDBY) {
			Daemon::log(Debug::exportBytes($this->look(1024), true));
			if (($hdr = $this->readExact(2)) === false) {
				return; // not enough data
			}
			$u = unpack('S', $hdr);
			$this->responseCode = $u[1];
			$this->state = static::STATE_PACKET_HDR;
		}
		if ($this->state === static::STATE_PACKET_HDR) {
			if ($this->responseCode === static::REPL_KVAL) {
				$this->result = [];
				if (($hdr = $this->readExact(1 + ($this->arch64 ? 16 : 8))) === false) {
					return; // not enough data
				}
				$this->encoding = Binary::getByte($hdr);
				$this->responseLength = $this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true);
				$this->resultTotalNum = $this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true);
				$this->pctReaded = 0;
				$this->state = static::STATE_PACKET_DATA;

			} else {
				if (($hdr = $this->readExact(1 + ($this->arch64 ? 8 : 4))) === false) {
					return; // not enough data
				}
				$this->encoding = Binary::getByte($hdr);
				$this->responseLength = $this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true);
				if ($this->responseCode === static::REPL_ERR_NOT_FOUND) {
					$this->response = null;
				}
				elseif ($this->responseCode === static::REPL_OK) {
					$this->response = true;
				}
				elseif (($this->responseCode === static::REPL_ERR_MEM) ||
						($this->responseCode === static::REPL_ERR_NAN) ||
						($this->responseCode === static::REPL_ERR_LOCKED)) {
					$this->response = false;
				} else {
					$this->state = static::STATE_PACKET_DATA;
				}
			}
		}
		if ($this->state === static::STATE_PACKET_DATA) {
			Daemon::log(Debug::dump([
					'responseCode' => $this->responseCode,
					'enc' => $this->encoding,
					'len' => $this->responseLength,
					'num' => $this->resultNum,
					'result' => $this->result,
			]));
			if ($this->responseCode === static::REPL_KVAL) {
				nextElement:
				$l = $this->getInputLength();
				Daemon::log(Debug::exportBytes($this->look(1024), true));
				if ($l < 9) {
					return;
				}
				if (($hdr = $this->lookExact($o = $this->arch64 ? 8 : 4)) === false) {
					return;
				}
				$keyLen = $this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true);
				if (($key = $this->lookExact($keyLen, $o) === false) {
					return;
				}
				$o += $keyLen;
				$valLenLen = $this->arch64 ? 8 : 4;
				if (($hdr = $this->lookExact($valLenLen, $o)) === false) {
					return;
				}
				$valLen = $this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true);
				if (($key = $this->lookExact($this->arch64 ? Binary::getQword($hdr, true) : Binary::getDword($hdr, true)) === false) {
					return;
				}
				
				if (++$this->resultReadedNum >= $this->resultTotalNum) {
					$this->isFinal = true;
				} else {
					goto nextElement;
				}
				:
			} else {
				if (($this->result = $this->readExact($this->responseLength)) === false) {
					$this->setWatermark($this->responseLength);
					return;
				}
				$this->isFinal = true;
				$this->resultTotalNum = 1;
				$this->resultReadedNum = 1;
			}
			Daemon::log(Debug::dump([
					'responseCode' => $this->responseCode,
					'enc' => $this->encoding,
					'len' => $this->responseLength,
					'result' => $this->result,
			]));
			if ($this->isFinal) {
				$this->state = static::STATE_STANDBY;
				$this->onResponse->executeOne($this);
				$this->encoding = null;
				$this->responseLength = null;
				$this->result = null;
				$this->resultTotalNum = null;
				$this->resultReadedNum = null;
				$this->isFinal = null;
			} else {
				$this->onResponse->executeAndKeepOne($this);
			}
		}
		goto start;
	}
}