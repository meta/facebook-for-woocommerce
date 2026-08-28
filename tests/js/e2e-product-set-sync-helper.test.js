/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

const {
	drainPendingSyncJobs,
	getProductSetMembershipState,
	hasProductSetMembership,
	waitForProductSetMembership,
} = require("../e2e/helpers/js/plugin/sync");

function createSyncResult({ success = true, productSets = [] } = {}) {
	return {
		success,
		sync_status: success ? "synced" : "not_synced",
		raw_data: {
			facebook_data: productSets.map((sets) => ({
				product_sets: sets,
			})),
		},
	};
}

describe("E2E product set sync helper", () => {
	let consoleLogSpy;

	beforeEach(() => {
		consoleLogSpy = jest.spyOn(console, "log").mockImplementation(() => {});
	});

	afterEach(() => {
		consoleLogSpy.mockRestore();
	});

	test("matches category retailer and product set IDs across product variations", () => {
		const result = createSyncResult({
			productSets: [
				[],
				[
					{
						id: "987654321098765432",
						retailer_id: "1234",
					},
				],
			],
		});

		expect(
			hasProductSetMembership(result, 1234, "987654321098765432")
		).toBe(true);
		expect(
			hasProductSetMembership(result, 1234, "987654321098765433")
		).toBe(false);
	});

	test("treats incomplete Facebook product set data as unknown", () => {
		const result = {
			success: true,
			raw_data: {
				facebook_data: [{}],
			},
		};

		expect(getProductSetMembershipState(result, 42, "set-1")).toBe(
			"unknown"
		);
	});

	test("polls until the product is synced into the expected set", async () => {
		const drainPendingJobs = jest.fn().mockResolvedValue({ success: true });
		const validateProduct = jest
			.fn()
			.mockResolvedValueOnce(createSyncResult({ success: false }))
			.mockResolvedValueOnce(createSyncResult({ productSets: [[]] }))
			.mockResolvedValueOnce(
				createSyncResult({
					productSets: [[{ id: "set-1", retailer_id: "42" }]],
				})
			);
		let currentTime = 0;
		const wait = jest.fn(async (milliseconds) => {
			currentTime += milliseconds;
		});

		const result = await waitForProductSetMembership({
			productId: 100,
			productName: "Test product",
			productSetRetailerId: 42,
			facebookProductSetId: "set-1",
			timeoutMs: 100,
			pollIntervalMs: 10,
			drainPendingJobs,
			validateProduct,
			wait,
			now: () => currentTime,
		});

		expect(result.success).toBe(true);
		expect(drainPendingJobs).toHaveBeenCalledTimes(1);
		expect(validateProduct).toHaveBeenCalledTimes(3);
		expect(wait).toHaveBeenCalledTimes(2);
	});

	test("does not accept missing membership until the product itself is synced", async () => {
		const drainPendingJobs = jest.fn().mockResolvedValue({ success: true });
		const validateProduct = jest
			.fn()
			.mockResolvedValueOnce(createSyncResult({ success: false }))
			.mockResolvedValueOnce(
				createSyncResult({
					productSets: [[{ id: "set-1", retailer_id: "42" }]],
				})
			)
			.mockResolvedValueOnce(createSyncResult({ productSets: [[]] }));
		let currentTime = 0;

		const result = await waitForProductSetMembership({
			productId: 100,
			productName: "Test product",
			productSetRetailerId: 42,
			facebookProductSetId: "set-1",
			expectedMembership: false,
			timeoutMs: 100,
			pollIntervalMs: 10,
			drainPendingJobs,
			validateProduct,
			wait: async (milliseconds) => {
				currentTime += milliseconds;
			},
			now: () => currentTime,
		});

		expect(result.success).toBe(true);
		expect(validateProduct).toHaveBeenCalledTimes(3);
	});

	test("fails with the last observed state when the deadline expires", async () => {
		const drainPendingJobs = jest.fn().mockResolvedValue({ success: true });
		const validateProduct = jest
			.fn()
			.mockResolvedValue(createSyncResult({ success: false }));
		let currentTime = 0;

		await expect(
			waitForProductSetMembership({
				productId: 100,
				productName: "Test product",
				productSetRetailerId: 42,
				facebookProductSetId: "set-1",
				timeoutMs: 10,
				pollIntervalMs: 10,
				drainPendingJobs,
				validateProduct,
				wait: async (milliseconds) => {
					currentTime += milliseconds;
				},
				now: () => currentTime,
			})
		).rejects.toThrow(
			"Last sync status: not_synced; membership state: unknown"
		);
		expect(validateProduct).toHaveBeenCalledTimes(1);
	});

	test("fails before remote validation when the local queue cannot drain", async () => {
		const validateProduct = jest.fn();

		await expect(
			waitForProductSetMembership({
				productId: 100,
				productName: "Test product",
				productSetRetailerId: 42,
				facebookProductSetId: "set-1",
				drainPendingJobs: jest.fn().mockResolvedValue({
					success: false,
					error: "queue failed",
				}),
				validateProduct,
			})
		).rejects.toThrow("queue failed");
		expect(validateProduct).not.toHaveBeenCalled();
	});

	test("shares concurrent queue drains and starts a fresh sequential drain", async () => {
		let finishDrain;
		const processJobs = jest.fn(
			() =>
				new Promise((resolve) => {
					finishDrain = resolve;
				})
		);

		const firstDrain = drainPendingSyncJobs(processJobs);
		const secondDrain = drainPendingSyncJobs(processJobs);

		await Promise.resolve();
		expect(processJobs).toHaveBeenCalledTimes(1);
		finishDrain({ success: true });
		await expect(firstDrain).resolves.toEqual({ success: true });
		await expect(secondDrain).resolves.toEqual({ success: true });

		processJobs.mockResolvedValueOnce({ success: true });
		await expect(drainPendingSyncJobs(processJobs)).resolves.toEqual({
			success: true,
		});
		expect(processJobs).toHaveBeenCalledTimes(2);
	});
});
