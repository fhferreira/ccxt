<?php

namespace ccxt;

include_once ('base/Exchange.php');

class coinfloor extends Exchange {

    public function describe () {
        return array_replace_recursive (parent::describe (), array (
            'id' => 'coinfloor',
            'name' => 'coinfloor',
            'rateLimit' => 1000,
            'countries' => 'UK',
            'hasCORS' => false,
            'urls' => array (
                'logo' => 'https://user-images.githubusercontent.com/1294454/28246081-623fc164-6a1c-11e7-913f-bac0d5576c90.jpg',
                'api' => 'https://webapi.coinfloor.co.uk:8090/bist',
                'www' => 'https://www.coinfloor.co.uk',
                'doc' => array (
                    'https://github.com/coinfloor/api',
                    'https://www.coinfloor.co.uk/api',
                ),
            ),
            'api' => array (
                'public' => array (
                    'get' => array (
                        'array (id)/ticker/',
                        'array (id)/order_book/',
                        'array (id)/transactions/',
                    ),
                ),
                'private' => array (
                    'post' => array (
                        'array (id)/balance/',
                        'array (id)/user_transactions/',
                        'array (id)/open_orders/',
                        'array (id)/cancel_order/',
                        'array (id)/buy/',
                        'array (id)/sell/',
                        'array (id)/buy_market/',
                        'array (id)/sell_market/',
                        'array (id)/estimate_sell_market/',
                        'array (id)/estimate_buy_market/',
                    ),
                ),
            ),
            'markets' => array (
                'BTC/GBP' => array ( 'id' => 'XBT/GBP', 'symbol' => 'BTC/GBP', 'base' => 'BTC', 'quote' => 'GBP' ),
                'BTC/EUR' => array ( 'id' => 'XBT/EUR', 'symbol' => 'BTC/EUR', 'base' => 'BTC', 'quote' => 'EUR' ),
                'BTC/USD' => array ( 'id' => 'XBT/USD', 'symbol' => 'BTC/USD', 'base' => 'BTC', 'quote' => 'USD' ),
                'BTC/PLN' => array ( 'id' => 'XBT/PLN', 'symbol' => 'BTC/PLN', 'base' => 'BTC', 'quote' => 'PLN' ),
                'BCH/GBP' => array ( 'id' => 'BCH/GBP', 'symbol' => 'BCH/GBP', 'base' => 'BCH', 'quote' => 'GBP' ),
            ),
        ));
    }

    public function fetch_balance ($params = array ()) {
        $symbol = null;
        if (array_key_exists ('symbol', $params))
            $symbol = $params['symbol'];
        if (array_key_exists ('id', $params))
            $symbol = $params['id'];
        if (!$symbol)
            throw new ExchangeError ($this->id . ' fetchBalance requires a $symbol param');
        // todo parse balance
        return $this->privatePostIdBalance (array (
            'id' => $this->market_id($symbol),
        ));
    }

    public function fetch_order_book ($symbol, $params = array ()) {
        $orderbook = $this->publicGetIdOrderBook (array_merge (array (
            'id' => $this->market_id($symbol),
        ), $params));
        return $this->parse_order_book($orderbook);
    }

    public function parse_ticker ($ticker, $market = null) {
        // rewrite to get the $timestamp from HTTP headers
        $timestamp = $this->milliseconds ();
        $symbol = null;
        if ($market)
            $symbol = $market['symbol'];
        return array (
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'high' => floatval ($ticker['high']),
            'low' => floatval ($ticker['low']),
            'bid' => floatval ($ticker['bid']),
            'ask' => floatval ($ticker['ask']),
            'vwap' => $this->safe_float($ticker, 'vwap'),
            'open' => null,
            'close' => null,
            'first' => null,
            'last' => floatval ($ticker['last']),
            'change' => null,
            'percentage' => null,
            'average' => null,
            'baseVolume' => null,
            'quoteVolume' => floatval ($ticker['volume']),
            'info' => $ticker,
        );
    }

    public function fetch_ticker ($symbol, $params = array ()) {
        $market = $this->market ($symbol);
        $ticker = $this->publicGetIdTicker (array_merge (array (
            'id' => $market['id'],
        ), $params));
        return $this->parse_ticker($ticker, $market);
    }

    public function parse_trade ($trade, $market) {
        $timestamp = $trade['date'] * 1000;
        return array (
            'info' => $trade,
            'id' => (string) $trade['tid'],
            'order' => null,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'symbol' => $market['symbol'],
            'type' => null,
            'side' => null,
            'price' => floatval ($trade['price']),
            'amount' => floatval ($trade['amount']),
        );
    }

    public function fetch_trades ($symbol, $params = array ()) {
        $market = $this->market ($symbol);
        $response = $this->publicGetIdTransactions (array_merge (array (
            'id' => $market['id'],
        ), $params));
        return $this->parse_trades($response, $market);
    }

    public function create_order ($symbol, $type, $side, $amount, $price = null, $params = array ()) {
        $order = array ( 'id' => $this->market_id($symbol) );
        $method = 'privatePostId' . $this->capitalize ($side);
        if ($type == 'market') {
            $order['quantity'] = $amount;
            $method .= 'Market';
        } else {
            $order['price'] = $price;
            $order['amount'] = $amount;
        }
        return $this->$method (array_merge ($order, $params));
    }

    public function cancel_order ($id, $symbol = null, $params = array ()) {
        return $this->privatePostIdCancelOrder (array ( 'id' => $id ));
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        // curl -k -u '[User ID]/[API key]:[Passphrase]' https://webapi.coinfloor.co.uk:8090/bist/XBT/GBP/balance/
        $url = $this->urls['api'] . '/' . $this->implode_params($path, $params);
        $query = $this->omit ($params, $this->extract_params($path));
        if ($api == 'public') {
            if ($query)
                $url .= '?' . $this->urlencode ($query);
        } else {
            $nonce = $this->nonce ();
            $body = $this->urlencode (array_merge (array ( 'nonce' => $nonce ), $query));
            $auth = $this->uid . '/' . $this->apiKey . ':' . $this->password;
            $signature = base64_encode ($auth);
            $headers = array (
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $signature,
            );
        }
        return array ( 'url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers );
    }
}

?>