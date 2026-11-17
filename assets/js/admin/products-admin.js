/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

jQuery( document ).ready( function( $ ) {

	const pagenow = window.pagenow.length ? window.pagenow : '',
	      typenow = window.typenow.length ? window.typenow : '';


	// individual product edit screen
	if ( 'product' === pagenow ) {

		/**
		 * Toggles (enables/disables) Facebook setting fields.
		 *
		 * @since 1.10.0
		 *
		 * @param {boolean} enabled whether the settings fields should be enabled or not
		 * @param {jQuery} $container a common ancestor of all the elements that can be enabled/disabled
		 */
		function toggleFacebookSettings( enabled, $container ) {

			$container.find( '.enable-if-sync-enabled' ).prop( 'disabled', ! enabled );

			// Also disable all select elements that don't have the enable-if-sync-enabled class
			$container.find( 'select' ).not( '.enable-if-sync-enabled' ).prop( 'disabled', ! enabled );
		}


		/**
		 * Toggles (shows/hides) Sync and show option and changes the select value if needed.
		 *
		 * @since 2.0.0
		 *
		 * @param {boolean} show whether the Sync and show option should be displayed or not
		 * @param {jQuery} $select the sync mode select
		 */
		function toggleSyncAndShowOption( show, $select ) {

			if ( ! show ) {

				// hide Sync and show option
				$select.find( 'option[value=\'sync_and_show\']' ).hide();

				if ( 'sync_and_show' === $select.val() ) {
					// change selected option to Sync and hide
					$select.val( 'sync_and_hide' );
				}

			} else {

				// show Sync and Show option
				$select.find( 'option[value=\'sync_and_show\']' ).show();

				// restore originally selected option
				if ( $select.prop( 'original' ) ) {
					$select.val( $select.prop( 'original' ) );
				}
			}
		}


		/**
		 * Disables and changes the checked status of the Sell on Instagram setting field.
		 *
		 * Additionally, shows/hides messages explaining that the product is not ready for Commerce.
		 *
		 * @since 2.1.0
		 *
		 * @param {boolean} enabled whether the setting field should be enabled or not
		 * @param {jQuery} $container a common ancestor of all the elements that need to modified
		 */
		function toggleFacebookSellOnInstagramSetting( enabled, $container ) {

			let $field = $container.find( '#wc_facebook_commerce_enabled' );
			let checked = $field.prop( 'original' );

			$field.prop( 'checked', enabled ? checked : false ).prop( 'disabled', ! enabled );

			// trigger change to hide fields based on the new state
			$field.trigger( 'change' );

			// restore previously stored value so that we can later restore the field to the status it had before we disabled it here
			$field.prop( 'original', checked );

			$container.find( '#product-not-ready-notice, #variable-product-not-ready-notice' ).hide();

			if ( isVariableProduct() && ! isSyncEnabledForVariableProduct() ) {
				$container.find( '#variable-product-not-ready-notice' ).show();
			} else if ( ! enabled ) {
				$container.find( '#product-not-ready-notice' ).show();
			}
		}


		/**
		 * Determines whether product properties are configured using appropriate values for Commerce.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isProductReadyForCommerce() {

			if ( ! isSyncEnabledForProduct() ) {
				return false;
			}

			if ( ! isPriceDefinedForProduct() ) {
				return false;
			}

			if ( ! isStockManagementEnabledForProduct() ) {
				return false;
			}

			return true;
		}


		/**
		 * Determines whether the product or one of its variations has Facebook Sync enabled.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isSyncEnabledForProduct() {

			if ( isVariableProduct() ) {
				return isSyncEnabledForVariableProduct();
			}

			return isSyncEnabledForSimpleProduct();
		}


		/**
		 * Determines whether the current product has synced variations.
		 *
		 * @since 2.1.0
		 *
		 * @returns {boolean}
		 */
		function isSyncEnabledForVariableProduct() {

			let $fields = $( '.js-variable-fb-sync-toggle' );

			// fallback to the value at page load if the variation fields haven't been loaded
			if ( 0 === $fields.length ) {
				return !! facebook_for_woocommerce_products_admin.is_sync_enabled_for_product;
			}

			// returns true if any of the Facebook Sync settings is set to a value other than 'sync_disabled'
			return !! $fields.map( ( i, element ) => $( element ).val() !== 'sync_disabled' ? element : null ).length;
		}


		/**
		 * Determines whether the product has Facebook Sync enabled.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isSyncEnabledForSimpleProduct() {

			return simpleProductSyncModeSelect.val() !== 'sync_disabled';
		}


		/**
		 * Determines whether the product has a Regular Price or Facebook Price defined.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isPriceDefinedForProduct() {

			if ( isVariableProduct() ) {
				// TODO: determine whether variations enabled for sync have a Regular Price or Facebook Price defined {WV 2020-09-19}
				return true;
			}

			return isPriceDefinedForSimpleProduct();
		}


		/**
		 * Determines whether the product is a Variable product.
		 *
		 * @since 2.1.2
		 *
		 * @return {boolean}
		 */
		function isVariableProduct() {

			var productType = $( 'select#product-type' ).val();

			return !! ( productType && productType.match( /variable/ ) );
		}


		/**
		 * Determines whether a simple product has a Regular Price or Facebook Price defined.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isPriceDefinedForSimpleProduct() {

			return !! ( $( '#_regular_price' ).val() || $( '#fb_product_price' ).val() );
		}


		/**
		 * Determines whether the product has Manage Stock enabled and Stock quantity defined.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isStockManagementEnabledForProduct() {

			// TODO: determine whether variations enabled for sync have stock management enabled {WV 2020-09-19}

			return isStockManagementEnabledForSimpleProduct();
		}


		/**
		 * Determines whether a simple product has Manage Stock enabled and Stock quantity defined.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function isStockManagementEnabledForSimpleProduct() {

			return !! ( $( '#_manage_stock' ).prop( 'checked' ) && $( '#_stock' ).val() );
		}


		/**
		 * Determines whether we should ask the user to select a Google Product Category.
		 *
		 * @since 2.1.0
		 *
		 * @return {boolean}
		 */
		function shouldShowMissingGoogleProductCategoryAlert() {

			if ( ! $( '#wc_facebook_commerce_enabled' ).prop( 'checked' ) ) {
				return false;
			}

			if ( ! isProductReadyForCommerce() ) {
				return false;
			}

			let selectedCategories = $( '.wc_facebook_commerce_fields .wc-facebook-google-product-category-select' ).map( ( i, element ) => {
				return $( element ).val() ? $( element ).val() : null;
			} );

			return selectedCategories.length < 2;
		}


		/**
		 * Shows an alert asking the user to select a Google product category and sub-category.
		 *
		 * @since 2.1.0
		 *
		 * @param {jQuery.Event} event a jQuery Event object for the submit event
		 * @returns {boolean}
		 */
		function showMissingGoogleProductCategoryAlert( event ) {

			event.preventDefault();

			alert( facebook_for_woocommerce_products_admin.i18n.missing_google_product_category_message );

			return false;
		}


		/**
		 * Store the original value of the given element for later use.
		 *
		 * @since 2.3.0
		 *
		 * @param {jQuery} $syncModeSelect a jQuery element object
		 */
		function storeSyncModeOriginalValue( $syncModeSelect ) {

			$syncModeSelect.attr( 'data-original-value', $syncModeSelect.val() );
		}


		/**
		 * Reverts the value of the given sync mode element to its original value.
		 *
		 * @since 2.3.0
		 *
		 * @param {jQuery} $syncModeSelect a jQuery element object
		 */
		function revertSyncModeToOriginalValue( $syncModeSelect ) {

			$syncModeSelect.val( $syncModeSelect.attr( 'data-original-value') );
		}

		/**
		 * Gets the target product ID based on the given sync select element.
		 *
		 * @since 2.3.0
		 *
		 * @param {jQuery} $syncModeSelect a jQuery element object
		 */
		function getSyncTargetProductID( $syncModeSelect ) {

			if ( simpleProductSyncModeSelect === $syncModeSelect ) {
				// simple product
				return $( 'input#post_ID' ).val();
			}

			// variable product
			return $syncModeSelect.closest( '.woocommerce_variation' ).find( 'input[name^=variable_post_id]' ).val();
		}

		/**
		 * Fills in product IDs to remove from Sync.
		 *
		 * @since 2.3.0
		 */
		function populateRemoveFromSyncProductIDsField() {

			$( facebook_for_woocommerce_products_admin.product_removed_from_sync_field_id ).val( removeFromSyncProductIDs.join( ',' ) );
		}


		/**
		 * Removes the given product ID from the list of product to delete from Sync.
		 *
		 * @since 2.3.0
		 *
		 * @param {String} productID Product ID to remove
		 */
		function removeProductIDFromUnSyncList( productID ) {

			removeFromSyncProductIDs = removeFromSyncProductIDs.filter( function ( value ) {
				return value !== productID;
			} );

			populateRemoveFromSyncProductIDsField();
		}

		let removeFromSyncProductIDs = [];

		// handle change events for the Sell on Instagram checkbox field
		$( '#facebook_options #wc_facebook_commerce_enabled' ).on( 'change', function() {

			let checked = $( this ).prop( 'checked' );

			// toggle visibility of all commerce fields
			if ( checked ) {
				$( '.wc_facebook_commerce_fields' ).show();
			} else {
				$( '.wc_facebook_commerce_fields').hide();
			}

			// toggle visibility of attribute fields
			if ( $( '.product_attributes' ).find( '.woocommerce_attribute' ).length ) {
				$( '.show_if_has_attributes' ).show();
			} else {
				$( '.show_if_has_attributes' ).hide();
			}

			$( this ).prop( 'original', checked );
		} ).trigger( 'change' );

		// toggle Facebook settings fields for simple products
		const simpleProductSyncModeSelect = $( '#wc_facebook_sync_mode' );
		const facebookSettingsPanel       = simpleProductSyncModeSelect.closest( '.woocommerce_options_panel' );

		// store sync mode original value for later use
		storeSyncModeOriginalValue( simpleProductSyncModeSelect );

		simpleProductSyncModeSelect.on( 'change', function() {

			let syncEnabled = simpleProductSyncModeSelect.val() !== 'sync_disabled';

			toggleFacebookSettings( syncEnabled, facebookSettingsPanel );

			if ( syncEnabled ) {
				removeProductIDFromUnSyncList( getSyncTargetProductID( simpleProductSyncModeSelect ) );
			}

			simpleProductSyncModeSelect.prop( 'original', simpleProductSyncModeSelect.val() );

		} ).trigger( 'change' );

		$( '#_virtual' ).on( 'change', function () {
			toggleSyncAndShowOption( ! $( this ).prop( 'checked' ), simpleProductSyncModeSelect );
		} ).trigger( 'change' );

		// Update the sync when catalog visibility changes.
		$( 'input[name=_visibility]' ).on ( 'change', function(){
			if ( $( this ).val() !== 'hidden' && $( this ).val() !== 'search' ) {

				if ( simpleProductSyncModeSelect.val() === 'sync_disabled' ) {
					simpleProductSyncModeSelect.val( 'sync_and_show' ).trigger( 'change' );;
				}
			}
		})

		const $productData = $( '#woocommerce-product-data' );

		// check whether the product meets the requirements for Commerce
		$productData.on(
			'change',
			'#_regular_price, #_manage_stock, #_stock, #wc_facebook_sync_mode, #fb_product_price',
			function( event ) {

				// allow validation handlers that run on change to run before we check any field values
				setTimeout( function() {
					toggleFacebookSellOnInstagramSetting( isProductReadyForCommerce(), $( '#facebook_options' ) );
				}, 1 );
			}
		);

		// toggle Facebook settings fields for variations
		$( '.woocommerce_variations' ).on( 'change', '.js-variable-fb-sync-toggle', function () {

			let $syncModeSelect = $( this );
			let syncEnabled     = $syncModeSelect.val() !== 'sync_disabled';

			toggleFacebookSettings( syncEnabled, $syncModeSelect.closest( '.wc-metabox-content' ) );
			toggleFacebookSellOnInstagramSetting( isProductReadyForCommerce(), $( '#facebook_options' ) );

			if ( syncEnabled ) {
				removeProductIDFromUnSyncList( getSyncTargetProductID( $syncModeSelect ) );
			}

			$syncModeSelect.prop( 'original', $syncModeSelect.val() );
		} );

		$productData.on( 'woocommerce_variations_loaded', function () {

			$productData.find( '.js-variable-fb-sync-toggle' ).each( function ( index, element ) {
				let $syncModeSelect = $( element );
				toggleFacebookSettings( $syncModeSelect.val() !== 'sync_disabled', $syncModeSelect.closest( '.wc-metabox-content' ) );
				$syncModeSelect.prop( 'original', $syncModeSelect.val() );
				storeSyncModeOriginalValue( $syncModeSelect );
			} );

			$( '.variable_is_virtual' ).on( 'change', function () {
				const jsSyncModeToggle = $( this ).closest( '.wc-metabox-content' ).find( '.js-variable-fb-sync-toggle' );
				toggleSyncAndShowOption( ! $( this ).prop( 'checked' ), jsSyncModeToggle );
			} );

			toggleFacebookSellOnInstagramSetting( isProductReadyForCommerce(), $( '#facebook_options' ) );
		} );

	// show/hide Custom Image URL setting
	$productData.on( 'change', '.js-fb-product-image-source', function() {

		let $container  = $( this ).closest( '.woocommerce_options_panel, .wc-metabox-content' );
		let imageSource = $( this ).val();

		// Hide all product-image-source-field form wrappers
		$container.find( '.product-image-source-field' ).closest( '.form-field' ).hide();

		// Show only the selected image source field form wrapper
		$container.find( `.show-if-product-image-source-${imageSource}` ).closest( '.form-field' ).show();

		// For variations, also handle the class-based approach for multiple images
		if ( $container.hasClass( 'wc-metabox-content' ) ) {
			// Remove 'show' class from all product-image-source-field elements
			$container.find( '.product-image-source-field' ).removeClass( 'show' );

			// Add 'show' class to the selected image source field
			$container.find( `.show-if-product-image-source-${imageSource}` ).addClass( 'show' );

			// Specifically handle multiple images thumbnails visibility
			let $thumbnailsContainer = $container.find( '.fb-product-images-thumbnails' );
			if (imageSource === 'multiple') {
				$thumbnailsContainer.show();
			} else {
				$thumbnailsContainer.hide();
			}
		}
	} );

	// show/hide Custom Video URL setting and Choose Video button
	$productData.on('change', '.js-fb-product-video-source', function() {
		updateVideoSourceUI( $( this ) );
	});

	// Update video-source UI without firing a synthetic change event
	function updateVideoSourceUI( $select ) {
		const $container = $select.closest( '.woocommerce_variation, .woocommerce_options_panel, .wc-metabox-content' );
		const videoSource = $select.val();

		// Hide all product-video-source-field elements and their form-field wrappers
		$container.find('.product-video-source-field').hide().removeClass('show');

		// Show only the selected video source field and its form-field wrapper
		$container.find('.show-if-product-video-source-' + videoSource)
				.show()
				.addClass('show');
	}

	// Update image-source UI without firing a synthetic change event
	function updateImageSourceUI( $select ) {
		const $container  = $select.closest( '.woocommerce_options_panel, .wc-metabox-content' );
		const imageSource = $select.val();

		$container.find( '.product-image-source-field' ).closest( '.form-field' ).hide();
		$container.find( `.show-if-product-image-source-${imageSource}` ).closest( '.form-field' ).show();

		if ( $container.hasClass( 'wc-metabox-content' ) ) {
			$container.find( '.product-image-source-field' ).removeClass( 'show' );
			$container.find( `.show-if-product-image-source-${imageSource}` ).addClass( 'show' );

			const $thumbnailsContainer = $container.find( '.fb-product-images-thumbnails' );
			$thumbnailsContainer.toggle( imageSource === 'multiple' );
		}
	}

	// React to user changes
	$productData.on( 'change', '.js-fb-product-image-source', function() {
		updateImageSourceUI( $( this ) );
	});

	// Initialize existing image-source fields on page load
	function initializeImageSourceStates() {
		$( '.js-fb-product-image-source' ).each(function() {
			updateImageSourceUI( $( this ) );
		});
	}

	// Initialize video source changes when DOM is ready
	function initializeVideoSourceStates() {
		$( '.js-fb-product-video-source' ).each(function() {
			updateVideoSourceUI( $( this ) );
		});
	}

	// Initialize on DOM ready
	$(document).ready(initializeImageSourceStates);
	$(document).ready(initializeVideoSourceStates);

	// Also initialize when variations are loaded
	$productData.on( 'woocommerce_variations_loaded', function() {
		$( '.js-variable-fb-sync-toggle:visible' ).trigger( 'change' );

		$('.js-fb-product-image-source').each(function() {
			updateImageSourceUI($(this));
		});

		$('.js-fb-product-video-source').each(function() {
			updateVideoSourceUI($(this));
		});

		$( '.variable_is_virtual:visible' ).trigger( 'change' );
	} );

		// open modal explaining sell on Instagram requirements
		$( '#facebook_options' ).on( 'click', '#product-not-ready-notice-open-modal', function( event ) {

			event.preventDefault();

			closeExistingModal();

			new $.WCBackboneModal.View( {
				target: 'facebook-for-woocommerce-modal',
				string: {
					message: facebook_for_woocommerce_products_admin.product_not_ready_modal_message,
					buttons: facebook_for_woocommerce_products_admin.product_not_ready_modal_buttons
				}
			} );
		} );

		// toggle Sell on Instagram checkbox on page load
		toggleFacebookSellOnInstagramSetting( isProductReadyForCommerce(), facebookSettingsPanel );

		// Enable disabled fields before form submission so their values are saved
		$( 'form#post' ).on( 'submit', function() {
			// Re-enable all fields that were disabled by toggleFacebookSettings
			// so their values are included in the form submission
			$( '#facebook_options .enable-if-sync-enabled' ).prop( 'disabled', false );
			$( '#facebook_options select' ).prop( 'disabled', false );
			$( '.woocommerce_variations .enable-if-sync-enabled' ).prop( 'disabled', false );
			$( '.woocommerce_variations select' ).prop( 'disabled', false );
		} );

		// fb product video support
		const $openMediaButton = $('#open_media_library');
		const $selectedVideoThumbnailsContainer = $('#fb_product_video_selected_thumbnails');
		const $hiddenInputField = $('#fb_product_video');
		let productGalleryFrame;
		let attachmentIds = $hiddenInputField.val() ? $hiddenInputField.val().split(',').map(Number) : [];

		/**
		 * Updates the hidden input field with the current list of attachment IDs.
		 */
		function updateHiddenInputField() {
			$hiddenInputField.val(attachmentIds.join(','));
		}

		/**
		 * Creates a video thumbnail element for the given attachment.
		 *
		 * @param {Object} attachment The attachment object containing video details.
		 * @returns {jQuery} The jQuery element representing the video thumbnail.
		 */
		function createVideoThumbnail(attachment) {
			const $videoThumbnail = $('<p>', { class: 'form-field video-thumbnail' });
			const $img = $('<img>', { src: attachment.icon });
			const $videoUrl = $('<span>', { text: attachment.url, 'data-attachment-id': attachment.id });
			const $removeButton = $('<a>', { href: '#', text: 'Remove', class: 'remove-video'});

			$removeButton.on('click', function (event) {
				event.preventDefault();
				removeVideoThumbnail(attachment.id, $videoThumbnail);
			});

			$videoThumbnail.append($img, $videoUrl, $removeButton);
			return $videoThumbnail;
		}

		/**
		 * Removes a video thumbnail and updates the list of attachment IDs.
		 *
		 * @param {Number} attachmentId The ID of the attachment to remove.
		 * @param {jQuery} $videoThumbnail The jQuery element representing the video thumbnail to remove.
		 */
		function removeVideoThumbnail(attachmentId, $videoThumbnail) {
			attachmentIds = attachmentIds.filter(id => id !== attachmentId);
			updateHiddenInputField();
			$videoThumbnail.remove();
		}

		/**
		 * Handles the selection of media items from the media library.
		 *
		 * @param {Object} selection The selection object containing the chosen media items.
		 */
		function handleMediaSelection(selection) {
			const selectedAttachmentIds = selection.map(attachment => attachment.id);
			const removedIds = attachmentIds.filter(id => !selectedAttachmentIds.includes(id));
			const newIds = selectedAttachmentIds.filter(id => !attachmentIds.includes(id));

			// Remove unselected video thumbnails
			$selectedVideoThumbnailsContainer.find('.form-field').each(function () {
				const $videoThumbnail = $(this);
				const videoAttachmentId = parseInt($videoThumbnail.find('span').data('attachment-id'), 10);
				if (removedIds.includes(videoAttachmentId)) {
					removeVideoThumbnail(videoAttachmentId, $videoThumbnail);
				}
			});

			// Add new video thumbnails
			selection.each(function (attachment) {
				attachment = attachment.toJSON();
				// Validate that the attachment is a video
				if (newIds.includes(attachment.id) && attachment.mime && attachment.mime.startsWith('video/')) {
					const $videoThumbnail = createVideoThumbnail(attachment);
					$selectedVideoThumbnailsContainer.append($videoThumbnail);
					attachmentIds.push(attachment.id);
				} else if (!attachment.mime.startsWith('video/')) {
					alert('Please select a valid video file.');
				}
			});

			updateHiddenInputField();
		}

		// Event handler for opening the media library
		$openMediaButton.on('click', function (e) {
			e.preventDefault();
			if (productGalleryFrame) {
				productGalleryFrame.open();
				return;
			}

			productGalleryFrame = wp.media({
				title: 'Select videos',
				button: { text: 'Save' },
				library: { type: 'video' },
				multiple: true
			});

			// Pre-select previously selected attachments
			productGalleryFrame.on('open', function () {
				productGalleryFrame.$el.addClass('fb-product-video-media-frame');
				const selection = productGalleryFrame.state().get('selection');
				attachmentIds.forEach(function (id) {
					const attachment = wp.media.attachment(id);
					attachment.fetch();
					selection.add(attachment ? [attachment] : []);
				});
			});

			// Handle selection of media
			productGalleryFrame.on('select', function () {
				const selection = productGalleryFrame.state().get('selection');
				handleMediaSelection(selection);
			});

			productGalleryFrame.open();
		});

		// Event handler for removing video thumbnails
		$selectedVideoThumbnailsContainer.on('click', '.remove-video', function (event) {
			event.preventDefault();
			const $button = $(this);
			const attachmentId = $button.data('attachment-id');
			removeVideoThumbnail(attachmentId, $button.closest('.form-field'));
		});

		// Facebook Product Images support for variations
		let variationImageFrames = {};

		/**
		 * Creates an image thumbnail element for variations.
		 *
		 * @param {Object} attachment The attachment object containing image details.
		 * @param {number} variationIndex The variation index.
		 * @returns {jQuery} The jQuery element representing the image thumbnail.
		 */
		function createImageThumbnail(attachment, variationIndex) {
			const $imageThumbnail = $('<p>', { class: 'form-field image-thumbnail' });
			const $img = $('<img>', {
				src: attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url
			});
			const $imageName = $('<span>', {
				text: attachment.filename || attachment.title,
				'data-attachment-id': attachment.id
			});
			const $removeButton = $('<a>', {
				href: '#',
				text: 'Remove',
				class: 'remove-image',
				'data-variation-index': variationIndex,
				'data-attachment-id': attachment.id
			});

			$removeButton.on('click', function (event) {
				event.preventDefault();
				removeImageThumbnail(attachment.id, variationIndex, $imageThumbnail);
			});

			$imageThumbnail.append($img, $imageName, $removeButton);
			return $imageThumbnail;
		}

		/**
		 * Removes an image thumbnail and updates the list of attachment IDs for a variation.
		 *
		 * @param {Number} attachmentId The ID of the attachment to remove.
		 * @param {Number} variationIndex The variation index.
		 * @param {jQuery} $imageThumbnail The jQuery element representing the image thumbnail to remove.
		 */
			function removeImageThumbnail(attachmentId, variationIndex, $imageThumbnail) {
		const $hiddenField = $(`#variable_fb_product_images${variationIndex}`);
		if (!$hiddenField.length) {
			return;
		}

		let attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

		attachmentIds = attachmentIds.filter(id => id !== attachmentId);
		$hiddenField.val(attachmentIds.join(','));
		$imageThumbnail.remove();
	}

		/**
		 * Handles the selection of image items from the media library for variations.
		 *
		 * @param {Object} selection The selection object containing the chosen media items.
		 * @param {number} variationIndex The variation index.
		 */
		function handleVariationImageSelection(selection, variationIndex) {
		const $container = $(`#fb_product_images_selected_thumbnails_${variationIndex}`);
		const $hiddenField = $(`#variable_fb_product_images${variationIndex}`);

		if (!$hiddenField.length) {
			return;
		}

		let attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

			const selectedAttachmentIds = selection.map(attachment => attachment.id);
			const removedIds = attachmentIds.filter(id => !selectedAttachmentIds.includes(id));
			const newIds = selectedAttachmentIds.filter(id => !attachmentIds.includes(id));

			// Remove unselected image thumbnails
			$container.find('.form-field').each(function () {
				const $imageThumbnail = $(this);
				const imageAttachmentId = parseInt($imageThumbnail.find('span').data('attachment-id'), 10);
				if (removedIds.includes(imageAttachmentId)) {
					removeImageThumbnail(imageAttachmentId, variationIndex, $imageThumbnail);
				}
			});

			// Add new image thumbnails
			selection.each(function (attachment) {
				attachment = attachment.toJSON();
				// Validate that the attachment is an image
				if (newIds.includes(attachment.id) && attachment.mime && attachment.mime.startsWith('image/')) {
					const $imageThumbnail = createImageThumbnail(attachment, variationIndex);
					$container.append($imageThumbnail);
					if (!attachmentIds.includes(attachment.id)) {
						attachmentIds.push(attachment.id);
					}
				} else if (newIds.includes(attachment.id) && (!attachment.mime || !attachment.mime.startsWith('image/'))) {
					alert('Please select a valid image file.');
				}
			});

			$hiddenField.val(attachmentIds.join(','));
		}

		// Event handler for opening the image library for variations
		$(document).on('click', '.fb-open-images-library', function (e) {
			e.preventDefault();

			const $button = $(this);
			const variationIndex = $button.data('variation-index');
			const variationId = $button.data('variation-id');

			if (variationImageFrames[variationIndex]) {
				variationImageFrames[variationIndex].open();
				return;
			}

			variationImageFrames[variationIndex] = wp.media({
				title: 'Select Images for Variation',
				button: { text: 'Use Images' },
				library: { type: 'image' },
				multiple: true
			});

					// Pre-select previously selected attachments
		variationImageFrames[variationIndex].on('open', function () {
		variationImageFrames[variationIndex].$el.addClass('fb-product-images-media-frame');
		const selection = variationImageFrames[variationIndex].state().get('selection');
		const $hiddenField = $(`#variable_fb_product_images${variationIndex}`);

		if (!$hiddenField.length) {
			return;
		}

		const attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

				attachmentIds.forEach(function (id) {
					const attachment = wp.media.attachment(id);
					attachment.fetch();
					selection.add(attachment ? [attachment] : []);
				});
			});

			// Handle selection of media
			variationImageFrames[variationIndex].on('select', function () {
				const selection = variationImageFrames[variationIndex].state().get('selection');
				handleVariationImageSelection(selection, variationIndex);
			});

			variationImageFrames[variationIndex].open();
		});

		// Event handler for removing image thumbnails from variations
		$(document).on('click', '.fb-product-images-thumbnails .remove-image', function (event) {
			event.preventDefault();
			const $button = $(this);
			const attachmentId = parseInt($button.data('attachment-id'), 10);

			// Get variation index from the thumbnails container ID
			const $thumbnailsContainer = $button.closest('.fb-product-images-thumbnails');
			const containerId = $thumbnailsContainer.attr('id'); // e.g., "fb_product_images_selected_thumbnails_0"
			const variationIndex = containerId ? parseInt(containerId.split('_').pop(), 10) : 0;

			removeImageThumbnail(attachmentId, variationIndex, $button.closest('.image-thumbnail'));
		});

		// Facebook Product Video support for variations
		let variationVideoFrames = {};

		/**
		 * Creates a video thumbnail element for variations.
		 *
		 * @param {Object} attachment The attachment object containing video details.
		 * @param {number} variationIndex The variation index.
		 * @returns {jQuery} The jQuery element representing the video thumbnail.
		 */
		function createVariationVideoThumbnail(attachment, variationIndex) {
			const $videoThumbnail = $('<p>', { class: 'form-field video-thumbnail' });
			const $img = $('<img>', { src: attachment.icon });
			const $videoUrl = $('<span>', {
				text: attachment.url,
				'data-attachment-id': attachment.id
			});
			const $removeButton = $('<a>', {
				href: '#',
				text: 'Remove',
				class: 'remove-video',
				'data-variation-index': variationIndex,
				'data-attachment-id': attachment.id
			});

			$removeButton.on('click', function (event) {
				event.preventDefault();
				removeVariationVideoThumbnail(attachment.id, variationIndex, $videoThumbnail);
			});

			$videoThumbnail.append($img, $videoUrl, $removeButton);
			return $videoThumbnail;
		}

		/**
		 * Removes a video thumbnail and updates the list of attachment IDs for a variation.
		 *
		 * @param {Number} attachmentId The ID of the attachment to remove.
		 * @param {Number} variationIndex The variation index.
		 * @param {jQuery} $videoThumbnail The jQuery element representing the video thumbnail to remove.
		 */
		function removeVariationVideoThumbnail(attachmentId, variationIndex, $videoThumbnail) {
			const $hiddenField = $(`#variable_fb_product_video${variationIndex}`);
			if (!$hiddenField.length) {
				return;
			}

			let attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

			attachmentIds = attachmentIds.filter(id => id !== attachmentId);
			$hiddenField.val(attachmentIds.join(','));
			$videoThumbnail.remove();
		}

		/**
		 * Handles the selection of video items from the media library for variations.
		 *
		 * @param {Object} selection The selection object containing the chosen media items.
		 * @param {number} variationIndex The variation index.
		 */
		function handleVariationVideoSelection(selection, variationIndex) {
			const $container = $(`#fb_product_video_selected_thumbnails_${variationIndex}`);
			const $hiddenField = $(`#variable_fb_product_video${variationIndex}`);

			if (!$hiddenField.length) {
				return;
			}

			let attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

			const selectedAttachmentIds = selection.map(attachment => attachment.id);
			const removedIds = attachmentIds.filter(id => !selectedAttachmentIds.includes(id));
			const newIds = selectedAttachmentIds.filter(id => !attachmentIds.includes(id));

			// Remove unselected video thumbnails
			$container.find('.form-field').each(function () {
				const $videoThumbnail = $(this);
				const videoAttachmentId = parseInt($videoThumbnail.find('span').data('attachment-id'), 10);
				if (removedIds.includes(videoAttachmentId)) {
					removeVariationVideoThumbnail(videoAttachmentId, variationIndex, $videoThumbnail);
				}
			});

		// Add new video thumbnails
		selection.each(function (attachment) {
			attachment = attachment.toJSON();

			const isAttachmentIdIncluded = newIds.includes(attachment.id);
			const isAttachmentVideo = attachment.mime && attachment.mime.startsWith('video/');

			// Validate that the attachment is a video
			if (isAttachmentIdIncluded && isAttachmentVideo) {
				const $videoThumbnail = createVariationVideoThumbnail(attachment, variationIndex);
				$container.append($videoThumbnail);
				if (!attachmentIds.includes(attachment.id)) {
					attachmentIds.push(attachment.id);
				}
			} else if (isAttachmentIdIncluded && !isAttachmentVideo) {
				alert('Please select a valid video file.');
			}
		});

			$hiddenField.val(attachmentIds.join(','));
		}

		// Event handler for opening the video library for variations
		$(document).on('click', '.fb-open-video-library', function (e) {
			e.preventDefault();

			const $button = $(this);
			const variationIndex = $button.data('variation-index');
			const variationId = $button.data('variation-id');

			if (variationVideoFrames[variationIndex]) {
				variationVideoFrames[variationIndex].open();
				return;
			}

			variationVideoFrames[variationIndex] = wp.media({
				title: 'Select Video for Variation',
				button: { text: 'Use Video' },
				library: { type: 'video' },
				multiple: true
			});

			// Pre-select previously selected attachments
			variationVideoFrames[variationIndex].on('open', function () {
			variationVideoFrames[variationIndex].$el.addClass('fb-product-video-media-frame');
			const selection = variationVideoFrames[variationIndex].state().get('selection');
			const $hiddenField = $(`#variable_fb_product_video${variationIndex}`);

			if (!$hiddenField.length) {
				return;
			}

			const attachmentIds = $hiddenField.val() ? $hiddenField.val().split(',').map(Number) : [];

				attachmentIds.forEach(function (id) {
					const attachment = wp.media.attachment(id);
					attachment.fetch();
					selection.add(attachment ? [attachment] : []);
				});
			});

			// Handle selection of media
			variationVideoFrames[variationIndex].on('select', function () {
				const selection = variationVideoFrames[variationIndex].state().get('selection');
				handleVariationVideoSelection(selection, variationIndex);
			});

			variationVideoFrames[variationIndex].open();
		});

		// Event handler for removing video thumbnails from variations
		$(document).on('click', '.fb-product-video-thumbnails .remove-video', function (event) {
			event.preventDefault();
			const $button = $(this);
			const attachmentId = parseInt($button.data('attachment-id'), 10);
			const variationIndex = parseInt($button.data('variation-index'), 10);

			removeVariationVideoThumbnail(attachmentId, variationIndex, $button.closest('.video-thumbnail'));
		});

	}


} );
