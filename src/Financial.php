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

    public function payment ($locationId, $customerId, $operatorId=false, $orderId, $description, array &$methods, array $paymentInfo, array $billingInfo, &$response) {
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
            $method['result'] = $this->{$method['type'] . 'Gateway'}->authorize($orderId, $description, $method['amount'], $billingInfo, $paymentInfo, $method['response']);
            if ($method['result'] !== true) {
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
            $method['result'] = $this->{$method['type'] . 'Gateway'}->payment($orderId, $description, $method['amount'], $billingInfo, $paymentInfo, $method['response']);
            if ($method['result'] !== true) {
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
                'location_id' => $locationId,
                'customer_id' => $customerId,
                'operator_id' => $operatorId,
                'order_id' => $orderId,
                'description' => $description,
                'payment_method' => $method['type'],
                'transaction_id' => isset($method['response']['transaction_id']) ? $method['response']['transaction_id'] : null,
                'type' => 'sale',
                'amount' => $method['amount'],
                'revenue' => isset($method['revenue']) ? $method['revenue'] : $method['amount'],
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
                return true;
            }
        }
        return false;
    }

    public function refund ($locationId, $customerId, $operatorId, $orderId, $description, $paymentMethod, $refundMethod, $amount, &$response, &$refundId=false) {
        $result = $this->{$method . 'Gateway'}->refund($description, $amount, $response);
        if ($response != true) {
            return false;
        }
        $refundId = new \MongoId();
        $this->db->collection('financial_transactions')->save([
            '_id' => $refundId,
            'location_id' => $locationId,
            'customer_id' => $customerId,
            'operator_id' => $operatorId,
            'order_id' => $orderId,
            'description' => $description,
            'payment_method' => $paymentMethod,
            'refund_method' => $refundMethod,
            'refund_giftcard_id' => isset($response['refund_giftcard_id']) ? $response['refund_giftcard_id'] : null,
            'refund_storecredit_id' => isset($response['refund_storecredit_id']) ? $response['refund_storecredit_id'] : null,
            'transaction_id' => isset($response['transaction_id']) ? $response['transaction_id'] : null, 
            'type' => 'refund',
            'amount' => $amount,
            'revenue' => isset($response['revenue']) ? $response['revenue'] : $amount,
            'created_date' => new \MongoDate(strtotime('now')),
            'response' => (array)$response
        ]);
        return true;
    }

    public function arrayToPaymentInfo ($document, $secured=false) {
        $array = [
            'creditcard_number' => $this->ifKeyElse($document, 'creditcard_number'),
            'creditcard_expiration_month' => $this->ifKeyElse($document, 'creditcard_expiration_month'),
            'creditcard_expiration_year' => $this->ifKeyElse($document, 'creditcard_expiration_year'),
            'creditcard_security_code' => $this->ifKeyElse($document, 'creditcard_security_code'),
            'creditcard_type' => $this->ifKeyElse($document, 'creditcard_type'),
            'payment_method' => $this->ifKeyElse($document, 'payment_method')
        ];
        if ($secured) {
            $array['creditcard_number'] = substr($array['creditcard_number'], -4);
        }
        return $array;
    }

    public function arrayToBillingInfo ($document) {
        return [
            'first_name' => $this->ifKeyElse($document, 'first_name'),
            'last_name' => $this->ifKeyElse($document, 'last_name'),
            'phone' => $this->ifKeyElse($document, 'phone'),
            'email' => $this->ifKeyElse($document, 'email'),
            'address' => $this->ifKeyElse($document, 'address'),
            'address2' => $this->ifKeyElse($document, 'address2'),
            'city' => $this->ifKeyElse($document, 'city'),
            'state' => $this->ifKeyElse($document, 'state'),
            'zipcode' => $this->ifKeyElse($document, 'zipcode'),
            'country' => $this->ifKeyElse($document, 'country', 'US')
        ];
    }

    private function ifKeyElse ($array, $key, $else=null) {
        if (isset($array[$key])) {
            return $array[$key];
        }
        return $else;
    }
}