<?php
/**
 * Opine\Financial
 *
 * Copyright (c)2013, 2014 Ryan Mahoney, https://github.com/Opine-Org <ryan@virtuecenter.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
namespace Opine;

class Financial {
	private $db;
	private $creditcardGateway;
	private $cashGateway;
	private $checkGateway;
	private $storecreditGateway;
	private $giftcardGateway;
	private $methodTypes = ['creditcard', 'cash', 'check', 'storecredit', 'giftcard'];

	public function __construct ($db, $creditcardGateway=false, $cashGateway=false, $checkGateway=false, $storecreditGateway=false, $giftcardGateway=false) {
		$this->db = $db;
		$this->creditcardGateway = $creditcardGateway;
		$this->cashGateway = $cashGateway;
		$this->checkGateway = $checkGateway;
		$this->storecreditGateway = $storecreditGateway;
		$this->giftcardGateway = $giftcardGateway;
	}

	public function payment ($orderId, $descritpion, array &$methods, array $paymentInfo, array $billingInfo, &$response) {
		//validate input
		foreach ($methods as $method) {
			if (!isset($method['type']) || empty($method['type'])) {
				throw new \Exception('Invalid payment attempt, malformed method array');
			}
			if (!in_array($method['type'], $this->methodTypes)) {
				throw new \Exception ('Unknown payment method type: ' . $method['type']);
			}
			if (!isset($method['amount']) || empty($method['amount'])) {
				throw new \Exception ('Payment method has no amount: ' . $method['type']);
			}
		}

		//authorize
		foreach ($methods as &$method) {
			$method['response'] = null;
			$method['result'] = null;
			$method['result'] = $this->{$method['type'] . 'Gateway'}->authorize($orderId, $descritpion, $method['amount'], $method['response']);
			if ($result !== true) {
				break;
			}
		}

		//check
		if ($this->rollback($methods, $response) == true) {
			return false;
		}
		
		//charge
		foreach ($methods as $method) {
			$method['response'] = null;
			$method['result'] = null;
			$method['result'] = $this->{$method['type'] . 'Gateway'}->payment($orderId, $descritpion, $method['amount'], $method['response']);
			if ($result !== true) {
				break;
			}
		}
		
		//re-check
		if ($this->rollback($methods, $response) == true) {
			return false;
		}

		//save in database
		foreach ($methods as &$method) {
			$transactionId = new \MongoId();
			$method['transaction_id'] = $transactionId;
			$this->db->collection('financial_transactions')->save([
				'_id' => $transactionId,
				'order_id' => $orderId,
				'description' => $description,
				'payment_method' => $method['type'],
				'type' => 'sale',
				'amount' => $method['amount'],
				'created_date' => new \MongoDate(strtotime('now'))
			]);
		}
		return true;
	}

	private function rollback ($methods, &$response) {
		$rollback = false;
		foreach ($methods as $method) {
			if ($method['result'] != true) {
				$rollback = true;
				$response = $method['response'];
				break;
			}
		}
		if ($rollback == true) {
			foreach ($methods as $method) {
				if ($method['result'] === true) {
					$this->{$method['type'] . 'Gateway'}->rollback($method['response']);
				}
			}
			return true;
		}
		return false;
	}

	public function refund ($orderId, $description, $method, $amount, &$response, &$refundId=false) {
		$result = $this->{$method . 'Gateway'}->refund($descritpion, $amount, $response);
		if ($response != true) {
			return false;
		}
		$refundId = new \MongoId();
		$this->db->collection('financial_transactions')->save([
			'_id' => $refundId,
			'order_id' => $orderId,
			'description' => $description,
			'payment_method' => $method,
			'type' => 'refund',
			'amount' => $method['amount'],
			'created_date' => new \MongoDate(strtotime('now')),
			'response' => (array)$response
		]);
		return true;
	}
}