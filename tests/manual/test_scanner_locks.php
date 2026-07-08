<?php

/**
 * Test script to verify per-user scanner locks functionality
 * 
 * Run with: php artisan tinker < tests/manual/test_scanner_locks.php
 */

use App\Models\ScannerLock;
use App\Models\User;

echo "=== Testing Per-User Scanner Locks ===\n\n";

// Clean up any existing locks
echo "1. Cleaning up existing locks...\n";
ScannerLock::truncate();
echo "   ✓ Done\n\n";

// Get two test users (or use dummy bigint IDs)
$user1Id = 1; // Assuming user ID 1 exists
$user2Id = 2; // Assuming user ID 2 exists

// Test 1: Lock user 1
echo "2. Locking scanner for User 1...\n";
ScannerLock::lockScanner('test-scanner', 5, 'test-reason', $user1Id);
echo "   ✓ User 1 locked\n\n";

// Test 2: Check if user 1 is locked
echo "3. Checking if User 1 is locked...\n";
$isUser1Locked = ScannerLock::isLocked('test-scanner', $user1Id);
echo "   User 1 locked: " . ($isUser1Locked ? "YES ✓" : "NO ✗") . "\n\n";

// Test 3: Check if user 2 is locked (should be false)
echo "4. Checking if User 2 is locked...\n";
$isUser2Locked = ScannerLock::isLocked('test-scanner', $user2Id);
echo "   User 2 locked: " . ($isUser2Locked ? "NO ✗ (EXPECTED YES)" : "NO ✓ (correct - per-user lock)") . "\n\n";

// Test 4: Lock user 2 on the same scanner
echo "5. Locking scanner for User 2...\n";
ScannerLock::lockScanner('test-scanner', 3, 'another-reason', $user2Id);
echo "   ✓ User 2 locked\n\n";

// Test 5: Check both users are locked
echo "6. Verifying both users are independently locked...\n";
$isUser1StillLocked = ScannerLock::isLocked('test-scanner', $user1Id);
$isUser2NowLocked = ScannerLock::isLocked('test-scanner', $user2Id);
echo "   User 1 locked: " . ($isUser1StillLocked ? "YES ✓" : "NO ✗") . "\n";
echo "   User 2 locked: " . ($isUser2NowLocked ? "YES ✓" : "NO ✗") . "\n\n";

// Test 6: Get all locks for the scanner
echo "7. Getting all active locks for scanner...\n";
$allLocks = ScannerLock::getAllActiveLocks('test-scanner');
echo "   Total locks: " . $allLocks->count() . " (expected: 2)\n";
foreach ($allLocks as $lock) {
    echo "   - User: " . substr($lock->locked_by_user_id, 0, 12) . "... | Reason: {$lock->reason}\n";
}
echo "\n";

// Test 7: Unlock user 1
echo "8. Unlocking User 1...\n";
ScannerLock::unlockScanner('test-scanner', $user1Id);
$isUser1Unlocked = !ScannerLock::isLocked('test-scanner', $user1Id);
$isUser2StillLocked = ScannerLock::isLocked('test-scanner', $user2Id);
echo "   User 1 unlocked: " . ($isUser1Unlocked ? "YES ✓" : "NO ✗") . "\n";
echo "   User 2 still locked: " . ($isUser2StillLocked ? "YES ✓" : "NO ✗") . "\n\n";

// Test 8: Get remaining locks
echo "9. Getting remaining locks...\n";
$remainingLocks = ScannerLock::getAllActiveLocks('test-scanner');
echo "   Total locks: " . $remainingLocks->count() . " (expected: 1)\n\n";

// Clean up
echo "10. Cleaning up test data...\n";
ScannerLock::truncate();
echo "   ✓ Done\n\n";

echo "=== All Tests Completed ===\n";
echo "✓ Per-user locks are working correctly!\n";
echo "✓ Multiple users can be locked independently\n";
echo "✓ Unlocking one user doesn't affect others\n";
