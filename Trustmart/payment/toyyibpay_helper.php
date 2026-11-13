<?php
/**
 * ToyyibPay Integration Helper
 * 
 * Provides functions to interact with ToyyibPay API
 * Documentation: https://toyyibpay.com/apireference/
 */

require_once __DIR__ . '/../config/payment.php';

class ToyyibPayHelper {
    
    private $secret_key;
    private $category_code;
    private $api_url;
    
    public function __construct() {
        if (!TOYYIBPAY_ENABLED) {
            throw new Exception('ToyyibPay is not enabled in configuration');
        }
        
        $this->secret_key = TOYYIBPAY_SECRET_KEY;
        $this->category_code = TOYYIBPAY_CATEGORY_CODE;
        $this->api_url = TOYYIBPAY_API_URL;
        
        if (empty($this->secret_key) || empty($this->category_code)) {
            throw new Exception('ToyyibPay credentials not configured');
        }
    }
    
    /**
     * Create a bill for payment
     * 
     * @param array $params {
     *     @type int $transaction_id
     *     @type string $payment_reference
     *     @type float $amount
     *     @type string $product_name
     *     @type string $buyer_name
     *     @type string $buyer_email
     *     @type string $buyer_phone
     * }
     * @return array Response with billCode and billpaymentLink
     */
    public function createBill($params) {
        $bill_data = [
            'userSecretKey' => $this->secret_key,
            'categoryCode' => $this->category_code,
            'billName' => substr($params['product_name'], 0, 100),
            'billDescription' => 'TrustMart Order #' . $params['transaction_id'],
            'billPriceSetting' => 1, // Fixed price
            'billPayorInfo' => 1, // Collect payer info
            'billAmount' => number_format($params['amount'], 2, '.', ''),
            'billReturnUrl' => PAYMENT_BASE_URL . '/payment/return.php?ref=' . urlencode($params['payment_reference']),
            'billCallbackUrl' => PAYMENT_BASE_URL . '/payment/callback.php',
            'billExternalReferenceNo' => $params['payment_reference'],
            'billTo' => $params['buyer_name'],
            'billEmail' => $params['buyer_email'],
            'billPhone' => $params['buyer_phone'] ?? '',
            'billSplitPayment' => 0,
            'billPaymentChannel' => '0', // FPX only (0), Credit card (1), Both (2)
            'billContentEmail' => 'Thank you for your payment!',
            'billChargeToCustomer' => 1 // Customer pays the processing fee
        ];
        
        $response = $this->makeRequest('/index.php/api/createBill', $bill_data);
        
        if (!isset($response[0]['BillCode'])) {
            throw new Exception('Failed to create ToyyibPay bill: ' . json_encode($response));
        }
        
        return [
            'billCode' => $response[0]['BillCode'],
            'billpaymentLink' => $this->api_url . '/' . $response[0]['BillCode']
        ];
    }
    
    /**
     * Get bill transactions to verify payment
     * 
     * @param string $billCode
     * @return array Transaction details
     */
    public function getBillTransactions($billCode) {
        $data = [
            'billCode' => $billCode
        ];
        
        return $this->makeRequest('/index.php/api/getBillTransactions', $data);
    }
    
    /**
     * Verify payment from callback
     * 
     * @param array $callback_params Parameters from ToyyibPay callback
     * @return bool True if payment is verified as successful
     */
    public function verifyPayment($callback_params) {
        // Re-check with API to prevent tampering
        if (!isset($callback_params['billcode'])) {
            return false;
        }
        
        $transactions = $this->getBillTransactions($callback_params['billcode']);
        
        if (empty($transactions)) {
            return false;
        }
        
        // Find matching transaction
        $txn = $transactions[0];
        
        // Verify payment is successful (status_id 1 = successful)
        if (isset($txn['billpaymentStatus']) && $txn['billpaymentStatus'] === '1') {
            // Verify amount matches
            if (isset($callback_params['amount']) && 
                abs(floatval($txn['billpaymentAmount']) - floatval($callback_params['amount'])) < 0.01) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Request a refund for a paid bill
     * 
     * @param array $params {
     *     @type string $billCode ToyyibPay bill code
     *     @type string $payment_reference Our internal payment reference
     *     @type float $refund_amount Amount to refund (must match original payment)
     *     @type string $refund_reason Reason for refund
     * }
     * @return array {
     *     @type bool $success Whether refund was initiated
     *     @type string $refund_reference Gateway refund transaction ID
     *     @type string $message Response message
     *     @type string $error Error message if failed
     * }
     */
    public function refundPayment($params) {
        // Note: ToyyibPay API documentation (as of 2024) shows refund endpoint
        // but may require manual approval or specific merchant setup
        
        $refund_data = [
            'userSecretKey' => $this->secret_key,
            'billCode' => $params['billCode'],
            'refundAmount' => number_format($params['refund_amount'], 2, '.', ''),
            'refundReason' => substr($params['refund_reason'] ?? 'Order cancelled', 0, 200),
            'refundExternalReferenceNo' => $params['payment_reference']
        ];
        
        try {
            $response = $this->makeRequest('/index.php/api/refundBill', $refund_data);
            
            // Check if refund was accepted
            if (isset($response[0]['RefundStatus'])) {
                $status = $response[0]['RefundStatus'];
                
                if ($status === 'success' || $status === 'pending') {
                    return [
                        'success' => true,
                        'refund_reference' => $response[0]['RefundCode'] ?? $params['billCode'] . '_REF',
                        'message' => 'Refund initiated successfully',
                        'status' => $status
                    ];
                }
            }
            
            // If we get here, refund was not successful
            return [
                'success' => false,
                'refund_reference' => null,
                'message' => 'Refund request failed',
                'error' => json_encode($response)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'refund_reference' => null,
                'message' => 'Refund API error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check refund status
     * 
     * @param string $refundCode Refund reference code
     * @return array Refund status details
     */
    public function getRefundStatus($refundCode) {
        $data = [
            'userSecretKey' => $this->secret_key,
            'refundCode' => $refundCode
        ];
        
        try {
            return $this->makeRequest('/index.php/api/getRefundStatus', $data);
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Make HTTP request to ToyyibPay API
     * 
     * @param string $endpoint API endpoint path
     * @param array $data POST data
     * @return array Decoded JSON response
     */
    private function makeRequest($endpoint, $data) {
        $url = $this->api_url . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('ToyyibPay API request failed: ' . $error);
        }
        
        if ($http_code !== 200) {
            throw new Exception('ToyyibPay API returned HTTP ' . $http_code);
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from ToyyibPay: ' . $response);
        }
        
        return $decoded;
    }
}
