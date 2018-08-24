<?php
/**
 * A cryptographic random generator class used for generating secret keys
 *
 * This is based in part on Drupal code as well as what we used in our own code
 * prior to introduction of this class.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @author Daniel Friesen
 * @file
 */
use Psr\Log\LoggerInterface;

class CryptRand {
	/**
	 * Minimum number of iterations we want to make in our drift calculations.
	 */
	const MIN_ITERATIONS = 1000;

	/**
	 * Number of milliseconds we want to spend generating each separate byte
	 * of the final generated bytes.
	 * This is used in combination with the hash length to determine the duration
	 * we should spend doing drift calculations.
	 */
	const MSEC_PER_BYTE = 0.5;

	/**
	 * A boolean indicating whether the previous random generation was done using
	 * cryptographically strong random number generator or not.
	 */
	protected $strong = null;

	/**
	 * List of functions to call to generate some random state
	 *
	 * @var callable[]
	 */
	protected $randomFuncs = [];

	/**
	 * List of files to generate some random state from
	 *
	 * @var string[]
	 */
	protected $randomFiles = [];

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	public function __construct( array $randomFuncs, array $randomFiles, LoggerInterface $logger ) {
		$this->randomFuncs = $randomFuncs;
		$this->randomFiles = $randomFiles;
		$this->logger = $logger;
	}

	/**
	 * Initialize an initial random state based off of whatever we can find
	 * @return string
	 */
	protected function initialRandomState() {
		// $_SERVER contains a variety of unstable user and system specific information
		// It'll vary a little with each page, and vary even more with separate users
		// It'll also vary slightly across different machines
		$state = serialize( $_SERVER );

		// Try to gather a little entropy from the different php rand sources
		$state .= rand() . uniqid( mt_rand(), true );

		// Include some information about the filesystem's current state in the random state
		$files = $this->randomFiles;

		// We know this file is here so grab some info about ourselves
		$files[] = __FILE__;

		// We must also have a parent folder, and with the usual file structure, a grandparent
		$files[] = __DIR__;
		$files[] = dirname( __DIR__ );

		foreach ( $files as $file ) {
			Wikimedia\suppressWarnings();
			$stat = stat( $file );
			Wikimedia\restoreWarnings();
			if ( $stat ) {
				// stat() duplicates data into numeric and string keys so kill off all the numeric ones
				foreach ( $stat as $k => $v ) {
					if ( is_numeric( $k ) ) {
						unset( $k );
					}
				}
				// The absolute filename itself will differ from install to install so don't leave it out
				$path = realpath( $file );
				if ( $path !== false ) {
					$state .= $path;
				} else {
					$state .= $file;
				}
				$state .= implode( '', $stat );
			} else {
				// The fact that the file isn't there is worth at least a
				// minuscule amount of entropy.
				$state .= '0';
			}
		}

		// Try and make this a little more unstable by including the varying process
		// id of the php process we are running inside of if we are able to access it
		if ( function_exists( 'getmypid' ) ) {
			$state .= getmypid();
		}

		// If available try to increase the instability of the data by throwing in
		// the precise amount of memory that we happen to be using at the moment.
		if ( function_exists( 'memory_get_usage' ) ) {
			$state .= memory_get_usage( true );
		}

		foreach ( $this->randomFuncs as $randomFunc ) {
			$state .= call_user_func( $randomFunc );
		}

		return $state;
	}

	/**
	 * Randomly hash data while mixing in clock drift data for randomness
	 *
	 * @param string $data The data to randomly hash.
	 * @return string The hashed bytes
	 * @author Tim Starling
	 */
	protected function driftHash( $data ) {
		// Minimum number of iterations (to avoid slow operations causing the
		// loop to gather little entropy)
		$minIterations = self::MIN_ITERATIONS;
		// Duration of time to spend doing calculations (in seconds)
		$duration = ( self::MSEC_PER_BYTE / 1000 ) * MWCryptHash::hashLength();
		// Create a buffer to use to trigger memory operations
		$bufLength = 10000000;
		$buffer = str_repeat( ' ', $bufLength );
		$bufPos = 0;

		// Iterate for $duration seconds or at least $minIterations number of iterations
		$iterations = 0;
		$startTime = microtime( true );
		$currentTime = $startTime;
		while ( $iterations < $minIterations || $currentTime - $startTime < $duration ) {
			// Trigger some memory writing to trigger some bus activity
			// This may create variance in the time between iterations
			$bufPos = ( $bufPos + 13 ) % $bufLength;
			$buffer[$bufPos] = ' ';
			// Add the drift between this iteration and the last in as entropy
			$nextTime = microtime( true );
			$delta = (int)( ( $nextTime - $currentTime ) * 1000000 );
			$data .= $delta;
			// Every 100 iterations hash the data and entropy
			if ( $iterations % 100 === 0 ) {
				$data = sha1( $data );
			}
			$currentTime = $nextTime;
			$iterations++;
		}
		$timeTaken = $currentTime - $startTime;
		$data = MWCryptHash::hash( $data );

		$this->logger->debug( "Clock drift calculation " .
			"(time-taken=" . ( $timeTaken * 1000 ) . "ms, " .
			"iterations=$iterations, " .
			"time-per-iteration=" . ( $timeTaken / $iterations * 1e6 ) . "us)" );

		return $data;
	}

	/**
	 * Return a rolling random state initially build using data from unstable sources
	 * @return string A new weak random state
	 */
	protected function randomState() {
		static $state = null;
		if ( is_null( $state ) ) {
			// Initialize the state with whatever unstable data we can find
			// It's important that this data is hashed right afterwards to prevent
			// it from being leaked into the output stream
			$state = MWCryptHash::hash( $this->initialRandomState() );
		}
		// Generate a new random state based on the initial random state or previous
		// random state by combining it with clock drift
		$state = $this->driftHash( $state );

		return $state;
	}

	/**
	 * Return a boolean indicating whether or not the source used for cryptographic
	 * random bytes generation in the previously run generate* call
	 * was cryptographically strong.
	 *
	 * @return bool Returns true if the source was strong, false if not.
	 */
	public function wasStrong() {
		if ( is_null( $this->strong ) ) {
			throw new RuntimeException( __METHOD__ . ' called before generation of random data' );
		}

		return $this->strong;
	}

	/**
	 * Generate a run of (ideally) cryptographically random data and return
	 * it in raw binary form.
	 * You can use CryptRand::wasStrong() if you wish to know if the source used
	 * was cryptographically strong.
	 *
	 * @param int $bytes The number of bytes of random data to generate
	 * @param bool $forceStrong Pass true if you want generate to prefer cryptographically
	 *                          strong sources of entropy even if reading from them may steal
	 *                          more entropy from the system than optimal.
	 * @return string Raw binary random data
	 */
	public function generate( $bytes, $forceStrong = false ) {
		$bytes = floor( $bytes );
		static $buffer = '';
		if ( is_null( $this->strong ) ) {
			// Set strength to false initially until we know what source data is coming from
			$this->strong = true;
		}

		if ( strlen( $buffer ) < $bytes ) {
			// If available make use of PHP 7's random_bytes
			// On Linux, getrandom syscall will be used if available.
			// On Windows CryptGenRandom will always be used
			// On other platforms, /dev/urandom will be used.
			// Avoids polyfills from before php 7.0
			// All error situations will throw Exceptions and or Errors
			if ( PHP_VERSION_ID >= 70000
				|| ( defined( 'HHVM_VERSION_ID' ) && HHVM_VERSION_ID >= 31101 )
			) {
				$rem = $bytes - strlen( $buffer );
				$buffer .= random_bytes( $rem );
			}
			if ( strlen( $buffer ) >= $bytes ) {
				$this->strong = true;
			}
		}

		if ( strlen( $buffer ) < $bytes && function_exists( 'mcrypt_create_iv' ) ) {
			// If available make use of mcrypt_create_iv URANDOM source to generate randomness
			// On unix-like systems this reads from /dev/urandom but does it without any buffering
			// and bypasses openbasedir restrictions, so it's preferable to reading directly
			// On Windows starting in PHP 5.3.0 Windows' native CryptGenRandom is used to generate
			// entropy so this is also preferable to just trying to read urandom because it may work
			// on Windows systems as well.
			$rem = $bytes - strlen( $buffer );
			$iv = mcrypt_create_iv( $rem, MCRYPT_DEV_URANDOM );
			if ( $iv === false ) {
				$this->logger->debug( "mcrypt_create_iv returned false." );
			} else {
				$buffer .= $iv;
				$this->logger->debug( "mcrypt_create_iv generated " . strlen( $iv ) .
					" bytes of randomness." );
			}
		}

		if ( strlen( $buffer ) < $bytes && function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$rem = $bytes - strlen( $buffer );
			$openssl_strong = false;
			$openssl_bytes = openssl_random_pseudo_bytes( $rem, $openssl_strong );
			if ( $openssl_bytes === false ) {
				$this->logger->debug( "openssl_random_pseudo_bytes returned false." );
			} else {
				$buffer .= $openssl_bytes;
				$this->logger->debug( "openssl_random_pseudo_bytes generated " .
					strlen( $openssl_bytes ) . " bytes of " .
					( $openssl_strong ? "strong" : "weak" ) . " randomness." );
			}
			if ( strlen( $buffer ) >= $bytes ) {
				// openssl tells us if the random source was strong, if some of our data was generated
				// using it use it's say on whether the randomness is strong
				$this->strong = !!$openssl_strong;
			}
		}

		// Only read from urandom if we can control the buffer size or were passed forceStrong
		if ( strlen( $buffer ) < $bytes &&
			( function_exists( 'stream_set_read_buffer' ) || $forceStrong )
		) {
			$rem = $bytes - strlen( $buffer );
			if ( !function_exists( 'stream_set_read_buffer' ) && $forceStrong ) {
				$this->logger->debug( "Was forced to read from /dev/urandom " .
					"without control over the buffer size." );
			}
			// /dev/urandom is generally considered the best possible commonly
			// available random source, and is available on most *nix systems.
			Wikimedia\suppressWarnings();
			$urandom = fopen( "/dev/urandom", "rb" );
			Wikimedia\restoreWarnings();

			// Attempt to read all our random data from urandom
			// php's fread always does buffered reads based on the stream's chunk_size
			// so in reality it will usually read more than the amount of data we're
			// asked for and not storing that risks depleting the system's random pool.
			// If stream_set_read_buffer is available set the chunk_size to the amount
			// of data we need. Otherwise read 8k, php's default chunk_size.
			if ( $urandom ) {
				// php's default chunk_size is 8k
				$chunk_size = 1024 * 8;
				if ( function_exists( 'stream_set_read_buffer' ) ) {
					// If possible set the chunk_size to the amount of data we need
					stream_set_read_buffer( $urandom, $rem );
					$chunk_size = $rem;
				}
				$random_bytes = fread( $urandom, max( $chunk_size, $rem ) );
				$buffer .= $random_bytes;
				fclose( $urandom );
				$this->logger->debug( "/dev/urandom generated " . strlen( $random_bytes ) .
					" bytes of randomness." );

				if ( strlen( $buffer ) >= $bytes ) {
					// urandom is always strong, set to true if all our data was generated using it
					$this->strong = true;
				}
			} else {
				$this->logger->debug( "/dev/urandom could not be opened." );
			}
		}

		// If we cannot use or generate enough data from a secure source
		// use this loop to generate a good set of pseudo random data.
		// This works by initializing a random state using a pile of unstable data
		// and continually shoving it through a hash along with a variable salt.
		// We hash the random state with more salt to avoid the state from leaking
		// out and being used to predict the /randomness/ that follows.
		if ( strlen( $buffer ) < $bytes ) {
			$this->logger->debug( __METHOD__ .
				": Falling back to using a pseudo random state to generate randomness." );
		}
		while ( strlen( $buffer ) < $bytes ) {
			$buffer .= MWCryptHash::hmac( $this->randomState(), strval( mt_rand() ) );
			// This code is never really cryptographically strong, if we use it
			// at all, then set strong to false.
			$this->strong = false;
		}

		// Once the buffer has been filled up with enough random data to fulfill
		// the request shift off enough data to handle the request and leave the
		// unused portion left inside the buffer for the next request for random data
		$generated = substr( $buffer, 0, $bytes );
		$buffer = substr( $buffer, $bytes );

		$this->logger->debug( strlen( $buffer ) .
			" bytes of randomness leftover in the buffer." );

		return $generated;
	}

	/**
	 * Generate a run of (ideally) cryptographically random data and return
	 * it in hexadecimal string format.
	 * You can use CryptRand::wasStrong() if you wish to know if the source used
	 * was cryptographically strong.
	 *
	 * @param int $chars The number of hex chars of random data to generate
	 * @param bool $forceStrong Pass true if you want generate to prefer cryptographically
	 *                          strong sources of entropy even if reading from them may steal
	 *                          more entropy from the system than optimal.
	 * @return string Hexadecimal random data
	 */
	public function generateHex( $chars, $forceStrong = false ) {
		// hex strings are 2x the length of raw binary so we divide the length in half
		// odd numbers will result in a .5 that leads the generate() being 1 character
		// short, so we use ceil() to ensure that we always have enough bytes
		$bytes = ceil( $chars / 2 );
		// Generate the data and then convert it to a hex string
		$hex = bin2hex( $this->generate( $bytes, $forceStrong ) );

		// A bit of paranoia here, the caller asked for a specific length of string
		// here, and it's possible (eg when given an odd number) that we may actually
		// have at least 1 char more than they asked for. Just in case they made this
		// call intending to insert it into a database that does truncation we don't
		// want to give them too much and end up with their database and their live
		// code having two different values because part of what we gave them is truncated
		// hence, we strip out any run of characters longer than what we were asked for.
		return substr( $hex, 0, $chars );
	}
}
