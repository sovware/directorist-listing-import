<?php
/** Bounded public-source transport. Only confirmed external dispatch calls this. */
defined( 'ABSPATH' ) || exit;

final class DLI_MCP_Source {
    const MAX_BYTES = 1048576;
    const MAX_PAGES = 3;

    /** Pure syntax validation in previews; DNS is resolved only at dispatch. */
    public static function url( string $url ): string {
        $parts = wp_parse_url( $url );
        DLI_MCP_Service::check( is_array( $parts ) && in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && empty( $parts['fragment'] ) && ( ! isset( $parts['port'] ) || in_array( $parts['port'], [ 80, 443 ], true ) ), 'Use a public HTTP(S) feed URL without credentials or a custom port.', 'invalid_source' );
        $host = strtolower( trim( $parts['host'], '[]' ) );
        DLI_MCP_Service::check( ! preg_match( '/(^localhost$|\.(localhost|local|internal|test|invalid)$)/i', $host ) && ( false !== strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ), 'Private sources are unsupported.', 'invalid_source' );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) { self::public_ip( $host ); }
        // Credentialed feeds and proxy-style URLs are outside the public-feed contract.
        parse_str( $parts['query'] ?? '', $query );
        foreach ( $query as $key => $value ) {
            DLI_MCP_Service::check( ! preg_match( '/(?:^|[_-])(?:api_?key|key|token|secret|auth(?:orization)?|credential|password|signature|sig|url|uri|proxy|endpoint)(?:$|[_-])/i', $key ) && is_scalar( $value ) && ! preg_match( '#https?://#i', (string) $value ), 'Credentialed feeds and fetch proxies are unsupported.', 'invalid_source' );
        }
        return esc_url_raw( $url, [ 'http', 'https' ] );
    }

    private static function public_ip( string $ip ): void {
        DLI_MCP_Service::check( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! preg_match( '/^(::ffff:|64:ff9b:|2002:)/i', $ip ), 'The source resolves to a nonpublic network.', 'unsafe_source' );
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $number = (int) sprintf( '%u', ip2long( $ip ) );
            DLI_MCP_Service::check( $number < 0xe0000000, 'Multicast and reserved sources are unsupported.', 'unsafe_source' );
            foreach ( [ [ '100.64.0.0', 10 ], [ '192.0.0.0', 24 ], [ '192.0.2.0', 24 ], [ '192.88.99.0', 24 ], [ '198.18.0.0', 15 ], [ '198.51.100.0', 24 ], [ '203.0.113.0', 24 ] ] as $range ) {
                $mask = ( 0xffffffff << ( 32 - $range[1] ) ) & 0xffffffff;
                DLI_MCP_Service::check( ( $number & $mask ) !== ( ip2long( $range[0] ) & $mask ), 'The source resolves to a nonpublic network.', 'unsafe_source' );
            }
        } else {
            DLI_MCP_Service::check( preg_match( '/^[23][0-9a-f]{3}:/i', $ip ) && ! preg_match( '/^(2001:(0*:|db8:|[12][0-9a-f]:))|^3fff:/i', $ip ), 'The source is outside public unicast space.', 'unsafe_source' );
        }
    }

    public static function descriptor( string $url ): string {
        $parts = wp_parse_url( $url );
        return ( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ?? 'unavailable' );
    }

    /** Pin each fetch to verified DNS answers, including each redirect target. */
    public static function request( string $url, array $args = [] ): array {
        DLI_MCP_Service::check( function_exists( 'curl_init' ), 'The safe import transport requires cURL.', 'prerequisite_missing' );
        for ( $redirect = 0; $redirect <= 3; ++$redirect ) {
            $url = self::url( $url );
            $host = wp_parse_url( $url, PHP_URL_HOST );
            $host = trim( $host, '[]' );
            $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : ( gethostbynamel( $host ) ?: [] );
            if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && function_exists( 'dns_get_record' ) ) {
                foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $record ) { if ( ! empty( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; } }
            }
            DLI_MCP_Service::check( $ips, 'The public source could not be resolved.', 'source_unavailable' );
            foreach ( $ips as $ip ) { self::public_ip( $ip ); }
            $port = wp_parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 );
            $pinned = false;
            $pin = static function ( $handle ) use ( $host, $port, $ips, &$pinned ): void {
                $address = false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0];
                curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $address ] );
                curl_setopt( $handle, CURLOPT_PROXY, '' );
                $pinned = true;
            };
            add_action( 'http_api_curl', $pin );
            try {
                $response = wp_safe_remote_request( $url, array_merge( $args, [ 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => self::MAX_BYTES + 1, 'reject_unsafe_urls' => true, 'cookies' => [] ] ) );
            } finally { remove_action( 'http_api_curl', $pin ); }
            if ( is_wp_error( $response ) ) { return [ 'state' => 'unknown', 'reason' => 'network_outcome_unknown' ]; }
            if ( ! $pinned ) { return [ 'state' => 'unknown', 'reason' => 'transport_not_verified' ]; }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 300 && $code < 400 ) {
                DLI_MCP_Service::check( 'GET' === ( $args['method'] ?? 'GET' ), 'Provider redirects are unsupported.', 'provider_redirect' );
                $location = wp_remote_retrieve_header( $response, 'location' );
                DLI_MCP_Service::check( is_string( $location ) && $location && $redirect < 3, 'The source exceeded its redirect limit.', 'source_redirect' );
                $url = WP_Http::make_absolute_url( $location, $url );
                continue;
            }
            $body = wp_remote_retrieve_body( $response );
            DLI_MCP_Service::check( strlen( $body ) <= self::MAX_BYTES, 'The source exceeds the one MiB payload limit.', 'source_too_large' );
            if ( $code < 200 || $code >= 300 ) { return [ 'state' => 'failed', 'reason' => 429 === $code ? 'provider_quota' : 'source_http_error' ]; }
            return [ 'state' => 'settled', 'body' => $body, 'url' => $url ];
        }
        return [ 'state' => 'failed', 'reason' => 'source_redirect' ];
    }

    public static function feed( array $args ): array {
        $response = self::request( $args['source_url'] );
        if ( 'settled' !== $response['state'] ) { return $response; }
        $body = $response['body'];
        if ( ! preg_match( '/<(?:(?:\w+:)?rss|feed|rdf:RDF)\b/i', $body ) ) {
            $previous = libxml_use_internal_errors( true );
            $dom = new DOMDocument();
            $dom->loadHTML( $body, LIBXML_NONET );
            libxml_clear_errors(); libxml_use_internal_errors( $previous );
            $feed_url = '';
            foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
                if ( false !== stripos( $link->getAttribute( 'rel' ), 'alternate' ) && preg_match( '/rss|atom/', $link->getAttribute( 'type' ) ) ) { $feed_url = WP_Http::make_absolute_url( $link->getAttribute( 'href' ), $response['url'] ); break; }
            }
            DLI_MCP_Service::check( $feed_url, 'No supported RSS or Atom feed was found.', 'unsupported_source' );
            $response = self::request( $feed_url );
            if ( 'settled' !== $response['state'] ) { return $response; }
            $body = $response['body'];
        }
        DLI_MCP_Service::check( ! preg_match( '/<!DOCTYPE|<!ENTITY/i', $body ), 'External XML declarations are unsupported.', 'unsafe_source' );
        if ( ! class_exists( 'SimplePie' ) ) { require_once ABSPATH . WPINC . '/class-simplepie.php'; }
        $feed = new SimplePie();
        $feed->enable_cache( false );
        $feed->set_raw_data( $body );
        $feed->set_autodiscovery_level( 0 );
        DLI_MCP_Service::check( $feed->init(), 'The feed could not be parsed.', 'unsupported_source' );
        $candidates = [];
        foreach ( $feed->get_items( 0, $args['sample_size'] ?? 5 ) as $entry ) {
            $mapped = Directorist_Listing_Import_Feed_Discovery::map_entry( $entry );
            $candidates[] = DLI_MCP_Listing_Writer::candidate( 'feed', $mapped['permalink'], $mapped );
        }
        return [ 'state' => 'settled', 'candidates' => $candidates, 'source' => self::descriptor( $response['url'] ), 'quality' => Directorist_Listing_Import_Feed_Discovery::quality_score( array_column( $candidates, 'fields' ) )['score'], 'next' => '' ];
    }

    /** One text-search POST, no geocode/details/photos/retry calls. */
    public static function places( array $args, string $page_token = '' ): array {
        $key = ( new \DLIG\Settings() )->get_api_key();
        DLI_MCP_Service::check( '' !== $key, 'Configure a Google Places key in the native settings.', 'provider_missing' );
        $body = [ 'textQuery' => trim( $args['keyword'] . ' ' . ( $args['location'] ?? '' ) ), 'pageSize' => min( ( new \DLIG\Settings() )->get_max_results(), $args['result_limit'] ?? 20 ) ];
        if ( isset( $args['latitude'], $args['longitude'] ) && ( $args['radius'] ?? 0 ) > 0 ) { $body['locationBias'] = [ 'circle' => [ 'center' => [ 'latitude' => $args['latitude'], 'longitude' => $args['longitude'] ], 'radius' => $args['radius'] ] ]; }
        if ( $page_token ) { $body['pageToken'] = $page_token; }
        $response = self::request( \DLIG\Google_Places_Client::SEARCH_ENDPOINT, [ 'method' => 'POST', 'headers' => [ 'Content-Type' => 'application/json', 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.nationalPhoneNumber,places.websiteUri,places.editorialSummary,nextPageToken' ], 'body' => wp_json_encode( $body ) ] );
        if ( 'settled' !== $response['state'] ) { return $response; }
        $data = json_decode( $response['body'], true );
        DLI_MCP_Service::check( is_array( $data ) && ! isset( $data['error'] ), 'The provider returned an invalid result.', 'provider_response_invalid' );
        $client = new \DLIG\Google_Places_Client(); $candidates = [];
        foreach ( array_slice( $data['places'] ?? [], 0, $body['pageSize'] ) as $place ) {
            $place = $client->normalise_detail( $place );
            $fields = [ 'title' => $place['name'], 'description' => $place['editorial_summary'], 'address' => $place['formatted_address'], 'phone' => $place['phone'], 'website' => $place['website'], 'email' => '' ];
            $candidates[] = DLI_MCP_Listing_Writer::candidate( 'places', $place['place_id'], $fields );
        }
        return [ 'state' => 'settled', 'source' => 'Google Places', 'candidates' => $candidates, 'quality' => Directorist_Listing_Import_Feed_Discovery::quality_score( array_column( $candidates, 'fields' ) )['score'], 'next' => is_string( $data['nextPageToken'] ?? null ) ? substr( $data['nextPageToken'], 0, 4096 ) : '' ];
    }
}
