<?php
/**
 * Interface and manager for deferred updates.
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
 * @file
 */

/**
 * Interface that deferrable updates should implement. Basically required so we
 * can validate input on DeferredUpdates::addUpdate()
 *
 * @since 1.19
 */
interface DeferrableUpdate {
	/**
	 * Perform the actual work
	 */
	function doUpdate();
}

/**
 * Class for managing the deferred updates
 *
 * Deferred updates can be run at the end of the request,
 * after the HTTP response has been sent. In CLI mode, updates
 * are only deferred until there is no local master DB transaction.
 * When updates are deferred, they go into a simple FIFO queue.
 *
 * @since 1.19
 */
class DeferredUpdates {
	/** @var DeferrableUpdate[] Updates to be deferred until the end of the request */
	private static $updates = array();
	/** @var bool Defer updates fully even in CLI mode */
	private static $forceDeferral = false;

	/**
	 * Add an update to the deferred list
	 * @param DeferrableUpdate $update Some object that implements doUpdate()
	 */
	public static function addUpdate( DeferrableUpdate $update ) {
		global $wgCommandLineMode;

		array_push( self::$updates, $update );
		if ( self::$forceDeferral ) {
			return;
		}

		// CLI scripts may forget to periodically flush these updates,
		// so try to handle that rather than OOMing and losing them.
		// Try to run the updates as soon as there is no local transaction.
		static $waitingOnTrx = false; // de-duplicate callback
		if ( $wgCommandLineMode && !$waitingOnTrx ) {
			$lb = wfGetLB();
			$dbw = $lb->getAnyOpenConnection( $lb->getWriterIndex() );
			// Do the update as soon as there is no transaction
			if ( $dbw && $dbw->trxLevel() ) {
				$waitingOnTrx = true;
				$dbw->onTransactionIdle( function() use ( &$waitingOnTrx ) {
					DeferredUpdates::doUpdates();
					$waitingOnTrx = false;
				} );
			} else {
				self::doUpdates();
			}
		}
	}

	/**
	 * Add a callable update.  In a lot of cases, we just need a callback/closure,
	 * defining a new DeferrableUpdate object is not necessary
	 * @see MWCallableUpdate::__construct()
	 * @param callable $callable
	 */
	public static function addCallableUpdate( $callable ) {
		self::addUpdate( new MWCallableUpdate( $callable ) );
	}

	/**
	 * Do any deferred updates and clear the list
	 *
	 * @param string $mode Use "enqueue" to use the job queue when possible [Default: run]
	 *   prevent lock contention
	 * @param string $oldMode Unused
	 */
	public static function doUpdates( $mode = 'run', $oldMode = '' ) {
		// B/C for ( $commit, $mode ) args
		$mode = $oldMode ?: $mode;
		if ( $mode === 'commit' ) {
			$mode = 'run';
		}

		$updates = self::$updates;

		while ( count( $updates ) ) {
			self::clearPendingUpdates();
			/** @var DataUpdate[] $dataUpdates */
			$dataUpdates = array();
			/** @var DeferrableUpdate[] $otherUpdates */
			$otherUpdates = array();
			foreach ( $updates as $update ) {
				if ( $update instanceof DataUpdate ) {
					$dataUpdates[] = $update;
				} else {
					$otherUpdates[] = $update;
				}
			}

			// Delegate DataUpdate execution to the DataUpdate class
			DataUpdate::runUpdates( $dataUpdates, $mode );
			// Execute the non-DataUpdate tasks
			foreach ( $otherUpdates as $update ) {
				try {
					$update->doUpdate();
					wfGetLBFactory()->commitMasterChanges();
				} catch ( Exception $e ) {
					// We don't want exceptions thrown during deferred updates to
					// be reported to the user since the output is already sent
					if ( !$e instanceof ErrorPageError ) {
						MWExceptionHandler::logException( $e );
					}
					// Make sure incomplete transactions are not committed and end any
					// open atomic sections so that other DB updates have a chance to run
					wfGetLBFactory()->rollbackMasterChanges();
				}
			}

			$updates = self::$updates;
		}
	}

	/**
	 * Clear all pending updates without performing them. Generally, you don't
	 * want or need to call this. Unit tests need it though.
	 */
	public static function clearPendingUpdates() {
		self::$updates = array();
	}

	/**
	 * @note This method is intended for testing purposes
	 * @param bool $value Whether to *always* defer updates, even in CLI mode
	 * @since 1.27
	 */
	public static function forceDeferral( $value ) {
		self::$forceDeferral = $value;
	}
}
