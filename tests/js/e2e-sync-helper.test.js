/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

const mockExecAsync = jest.fn();
const mockExecSync = jest.fn();

jest.mock("child_process", () => {
	const { promisify } = jest.requireActual("util");
	const exec = jest.fn();

	exec[promisify.custom] = (...args) => mockExecAsync(...args);

	return {
		exec,
		execSync: (...args) => mockExecSync(...args),
	};
});

const PRODUCT_RESULT = {
	success: true,
	sync_status: "synced",
	raw_data: {
		facebook_data: [],
	},
};

const UNSYNCED_PRODUCT_RESULT = {
	success: false,
	sync_status: "not_synced",
	raw_data: {
		facebook_data: [],
	},
};

const CATEGORY_RESULT = {
	success: true,
	sync_status: "synced",
	facebook_product_set_id: "set-123",
	retailer_id: "456",
	mismatches: [],
	raw_data: {
		facebook_data: {},
	},
};

function successfulCommandResult(command) {
	if (command.includes("process-sync-jobs.php")) {
		return {
			stdout: 'PHP notice before JSON\n{"success":true,"jobs_processed":1}',
			stderr: "",
		};
	}

	if (command.includes("FacebookSyncValidator")) {
		return { stdout: JSON.stringify(PRODUCT_RESULT), stderr: "" };
	}

	if (command.includes("CategorySyncValidator")) {
		return { stdout: JSON.stringify(CATEGORY_RESULT), stderr: "" };
	}

	return {
		stdout: JSON.stringify({
			connected: true,
			access_token: true,
			catalog_id: true,
			pixel_id: true,
		}),
		stderr: "",
	};
}

describe("E2E sync helper", () => {
	let consoleLogSpy;
	let consoleWarnSpy;

	beforeEach(() => {
		jest.resetModules();
		mockExecAsync.mockReset();
		mockExecSync.mockReset();
		mockExecSync.mockReturnValue("/usr/local/bin/wp\n");
		process.env.WORDPRESS_PATH = "/tmp/wordpress";
		process.env.WP_CLI_PATH = "/usr/local/bin/wp";
		delete process.env.PHP_BIN;
		delete process.env.USE_PHP_NO_INI;

		consoleLogSpy = jest.spyOn(console, "log").mockImplementation(() => {});
		consoleWarnSpy = jest
			.spyOn(console, "warn")
			.mockImplementation(() => {});
	});

	afterEach(() => {
		consoleLogSpy.mockRestore();
		consoleWarnSpy.mockRestore();
	});

	test("drains pending jobs before product validation and preserves zero-retry arguments", async () => {
		const commands = [];
		mockExecAsync.mockImplementation(async (command) => {
			commands.push(command);
			if (command.includes("FacebookSyncValidator")) {
				return {
					stdout: JSON.stringify(UNSYNCED_PRODUCT_RESULT),
					stderr: "",
				};
			}
			return successfulCommandResult(command);
		});

		const {
			validateFacebookSync,
		} = require("../e2e/helpers/js/plugin/sync");
		const result = await validateFacebookSync(123, "Deleted product", 0, 0);

		expect(result).toEqual(UNSYNCED_PRODUCT_RESULT);
		expect(commands[0]).toBe("php process-sync-jobs.php");
		expect(commands[1]).toContain(
			"get_connection_handler()->is_connected()"
		);
		expect(commands[2]).toContain("new FacebookSyncValidator(123, 0, 0)");
	});

	test("shares one queue drain across concurrent product and category validators", async () => {
		const commands = [];
		let finishDrain;

		mockExecAsync.mockImplementation((command) => {
			commands.push(command);

			if (command.includes("process-sync-jobs.php")) {
				return new Promise((resolve) => {
					finishDrain = () =>
						resolve(successfulCommandResult(command));
				});
			}

			return Promise.resolve(successfulCommandResult(command));
		});

		const {
			validateCategorySync,
			validateFacebookSync,
		} = require("../e2e/helpers/js/plugin/sync");

		const productValidation = validateFacebookSync(123, "Product", 0, 1);
		const categoryValidation = validateCategorySync(456, "Category", 0, 1);

		expect(
			commands.filter((command) =>
				command.includes("process-sync-jobs.php")
			)
		).toHaveLength(1);

		finishDrain();

		await expect(productValidation).resolves.toEqual(PRODUCT_RESULT);
		await expect(categoryValidation).resolves.toEqual(CATEGORY_RESULT);
		expect(
			commands.filter((command) =>
				command.includes("process-sync-jobs.php")
			)
		).toHaveLength(1);
		expect(
			commands.some((command) =>
				command.includes("FacebookSyncValidator")
			)
		).toBe(true);
		expect(
			commands.some((command) =>
				command.includes("CategorySyncValidator")
			)
		).toBe(true);
	});

	test("continues validation when the best-effort queue drain fails", async () => {
		const commands = [];
		mockExecAsync.mockImplementation(async (command) => {
			commands.push(command);

			if (command.includes("process-sync-jobs.php")) {
				throw new Error("queue drain timed out");
			}

			return successfulCommandResult(command);
		});

		const {
			validateFacebookSync,
		} = require("../e2e/helpers/js/plugin/sync");

		await expect(
			validateFacebookSync(789, "Product", 0, 1)
		).resolves.toEqual(PRODUCT_RESULT);
		expect(
			commands.some((command) =>
				command.includes("FacebookSyncValidator")
			)
		).toBe(true);
		expect(consoleWarnSpy).toHaveBeenCalledWith(
			expect.stringContaining("queue drain timed out")
		);
	});
});
