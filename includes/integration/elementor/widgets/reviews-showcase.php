<?php
/**
 * TrustScript Reviews Showcase Widget
 *
 * @package TrustScript
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TrustScript_Reviews_Showcase_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'trustscript_reviews_showcase';
	}

	public function get_title() {
		return __( 'Reviews Showcase', 'trustscript' );
	}

	public function get_icon() {
		return 'eicon-testimonial';
	}

	public function get_categories() {
		return array( 'trustscript' );
	}

	public function get_keywords() {
		return array( 'reviews', 'showcase', 'testimonials', 'trustscript', 'rating' );
	}

	public function get_script_depends() {
		return array( 'trustscript-marquee-slider' );
	}

	public function get_style_depends() {
		return array( 'trustscript-marquee-slider' );
	}

	protected function register_controls() {

		$this->start_controls_section( 'review_selection_section', array(
			'label' => __( 'Review Selection', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'selection_method', array(
			'label'   => __( 'Selection Method', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'manual',
			'options' => array(
				'manual'        => __( 'Manual Selection', 'trustscript' ),
				'recent'        => __( 'Recent Reviews', 'trustscript' ),
				'highest_rated' => __( 'Highest Rated', 'trustscript' ),
				'category'      => __( 'By Product Category', 'trustscript' ),
			),
		) );

		$this->add_control( 'selected_reviews', array(
			'label'       => __( 'Select Reviews', 'trustscript' ),
			'type'        => \Elementor\Controls_Manager::SELECT2,
			'multiple'    => true,
			'options'     => $this->get_reviews_for_selection(),
			'label_block' => true,
			'condition'   => array( 'selection_method' => 'manual' ),
		) );

		$categories = array();
		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[ $term->term_id ] = $term->name;
				}
			}
		}

		$this->add_control( 'product_category', array(
			'label'       => __( 'Product Category', 'trustscript' ),
			'type'        => \Elementor\Controls_Manager::SELECT,
			'options'     => $categories,
			'label_block' => true,
			'condition'   => array( 'selection_method' => 'category' ),
		) );

		$this->add_control( 'minimum_rating', array(
			'label'   => __( 'Minimum Star Rating', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => '3',
			'options' => array(
				'3' => __( '3 Stars & Above', 'trustscript' ),
				'4' => __( '4 Stars & Above', 'trustscript' ),
			),
		) );

		$this->add_control( 'total_reviews', array(
		'label'   => __( 'Number of Reviews', 'trustscript' ),
		'type'    => \Elementor\Controls_Manager::NUMBER,
		'min'     => 1,
		'max'     => 50,
		'step'    => 1,
		'default' => 6,
	) );

		$this->end_controls_section();
		$this->start_controls_section( 'display_section', array(
			'label' => __( 'Display Settings', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		) );

		$this->add_control( 'layout', array(
			'label'   => __( 'Layout', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'grid',
			'options' => array(
				'grid'        => __( 'Grid', 'trustscript' ),
				'marquee'     => __( 'Marquee (Infinite Scroll)', 'trustscript' ),
				'image-first' => __( 'Image-First Gallery', 'trustscript' ),
			),
		) );

		$this->add_responsive_control( 'columns', array(
			'label'          => __( 'Columns', 'trustscript' ),
			'type'           => \Elementor\Controls_Manager::SELECT,
			'default'        => '3',
			'tablet_default' => '2',
			'mobile_default' => '1',
			'options'        => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4' ),
			'condition'      => array( 'layout' => array( 'grid', 'image-first' ) ),
		) );

		$this->add_responsive_control( 'grid_gap', array(
			'label'      => __( 'Gap Between Cards (Rows & Columns)', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 80, 'step' => 2 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 20 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-reviews-grid' => 'gap: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-reviews-image-first' => 'gap: {{SIZE}}{{UNIT}};',
			),
			'condition'  => array( 'layout' => array( 'grid', 'image-first' ) ),
		) );

		$this->add_control( 'show_verification_badge', array(
			'label'        => __( 'Show Verification Badge', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Show', 'trustscript' ),
			'label_off'    => __( 'Hide', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'show_product_name', array(
			'label'        => __( 'Show Product Name', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Show', 'trustscript' ),
			'label_off'    => __( 'Hide', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'product_name_length', array(
			'label'     => __( 'Product Name Length (words)', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::NUMBER,
			'min'       => 10,
			'max'       => 200,
			'step'      => 1,
			'default'   => 50,
			'condition' => array( 'show_product_name' => 'yes' ),
		) );

		$this->add_control( 'show_customer_name', array(
			'label'        => __( 'Show Customer Name', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Show', 'trustscript' ),
			'label_off'    => __( 'Hide', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'show_customer_label', array(
			'label'        => __( 'Show "Verified Buyer" Label', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Show', 'trustscript' ),
			'label_off'    => __( 'Hide', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => array( 'show_customer_name' => 'yes' ),
		) );

		$this->add_control( 'excerpt_length', array(
			'label'   => __( 'Review Text Length (words)', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'min'     => 10,
			'max'     => 200,
			'step'    => 5,
			'default' => 50,
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'image_first_settings_section', array(
			'label'     => __( 'Image-First Settings', 'trustscript' ),
			'tab'       => \Elementor\Controls_Manager::TAB_CONTENT,
			'condition' => array( 'layout' => 'image-first' ),
		) );

		$this->add_responsive_control( 'image_height', array(
			'label'      => __( 'Image Height', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 150, 'max' => 600, 'step' => 10 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 300 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-image-first-card-image' => 'height: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->add_control( 'image_object_fit', array(
			'label'   => __( 'Image Fit', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'cover',
			'options' => array(
				'cover'      => __( 'Cover', 'trustscript' ),
				'contain'    => __( 'Contain', 'trustscript' ),
				'fill'       => __( 'Fill', 'trustscript' ),
				'none'       => __( 'None (original size)', 'trustscript' ),
				'scale-down' => __( 'Scale Down', 'trustscript' ),
			),
			'selectors' => array(
				'{{WRAPPER}} .trustscript-image-first-card-image' => 'object-fit: {{VALUE}};',
			),
		) );

		$this->add_control( 'image_object_position', array(
			'label'   => __( 'Image Position', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'center center',
			'options' => array(
				'center center' => __( 'Center Center', 'trustscript' ),
				'center top'    => __( 'Center Top', 'trustscript' ),
				'center bottom' => __( 'Center Bottom', 'trustscript' ),
				'left top'      => __( 'Left Top', 'trustscript' ),
				'left center'   => __( 'Left Center', 'trustscript' ),
				'left bottom'   => __( 'Left Bottom', 'trustscript' ),
				'right top'     => __( 'Right Top', 'trustscript' ),
				'right center'  => __( 'Right Center', 'trustscript' ),
				'right bottom'  => __( 'Right Bottom', 'trustscript' ),
			),
			'selectors' => array(
				'{{WRAPPER}} .trustscript-image-first-card-image' => 'object-position: {{VALUE}};',
			),
			'condition' => array( 'image_object_fit' => 'cover' ),
		) );

		$this->add_control( 'images_only', array(
			'label'       => __( 'Show Only Reviews With Images', 'trustscript' ),
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'label_on'    => __( 'Yes', 'trustscript' ),
			'label_off'   => __( 'No', 'trustscript' ),
			'return_value' => 'yes',
			'default'     => '',
		) );

		$this->add_control( 'image_hover_overlay', array(
			'label'        => __( 'Darken Image on Card Hover', 'trustscript' ),
			'description'  => __( 'Shows a subtle dark gradient over the photo when the card is hovered.', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Yes', 'trustscript' ),
			'label_off'    => __( 'No', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
			'separator'    => 'before',
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'marquee_settings_section', array(
			'label'     => __( 'Marquee Settings', 'trustscript' ),
			'tab'       => \Elementor\Controls_Manager::TAB_CONTENT,
			'condition' => array( 'layout' => 'marquee' ),
		) );

		$this->add_control( 'marquee_speed', array(
			'label'   => __( 'Animation Speed (seconds)', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'min'     => 10,
			'max'     => 120,
			'step'    => 2,
			'default' => 32,
			'description' => __( 'Higher = slower animation', 'trustscript' ),
		) );

		$this->add_control( 'marquee_pause_hover', array(
			'label'        => __( 'Pause on Hover', 'trustscript' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Yes', 'trustscript' ),
			'label_off'    => __( 'No', 'trustscript' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		) );

		$this->add_control( 'marquee_direction', array(
			'label'     => __( 'Scroll Direction', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'left',
			'options'   => array(
				'left'  => __( 'Left (default)', 'trustscript' ),
				'right' => __( 'Right', 'trustscript' ),
			),
			'separator' => 'before',
		) );

		$this->add_control( 'marquee_card_width', array(
			'label'   => __( 'Card Width (px)', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'min'     => 200,
			'max'     => 600,
			'step'    => 10,
			'default' => 320,
			'selectors' => array(
				'{{WRAPPER}} .trustscript-marquee-slider' => '--ts-marquee-card-width: {{VALUE}}px;',
			),
		) );

		$this->add_control( 'marquee_gap', array(
			'label'   => __( 'Gap Between Cards (px)', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'min'     => 0,
			'max'     => 80,
			'step'    => 4,
			'default' => 24,
			'selectors' => array(
				'{{WRAPPER}} .trustscript-marquee-slider' => '--ts-marquee-gap: {{VALUE}}px;',
			),
		) );

		$this->add_control( 'marquee_fade_color', array(
			'label'       => __( 'Edge Fade Color', 'trustscript' ),
			'description' => __( 'Should match your section background color.', 'trustscript' ),
			'type'        => \Elementor\Controls_Manager::COLOR,
			'default'     => '#faf8f5',
			'selectors'   => array(
				'{{WRAPPER}} .trustscript-marquee-slider::before' => 'background: linear-gradient(to right, {{VALUE}} 0%, transparent 100%);',
				'{{WRAPPER}} .trustscript-marquee-slider::after'  => 'background: linear-gradient(to left,  {{VALUE}} 0%, transparent 100%);',
			),
			'separator' => 'before',
		) );

		$this->add_responsive_control( 'marquee_fade_width', array(
			'label'      => __( 'Edge Fade Width', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', '%' ),
			'range'      => array(
				'px' => array( 'min' => 0, 'max' => 300 ),
				'%'  => array( 'min' => 0, 'max' => 30 ),
			),
			'default'   => array( 'unit' => 'px', 'size' => 160 ),
			'selectors' => array(
				'{{WRAPPER}} .trustscript-marquee-slider::before,
				 {{WRAPPER}} .trustscript-marquee-slider::after' => 'width: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'card_style_section', array(
			'label' => __( 'Review Card', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_responsive_control( 'card_alignment', array(
			'label'     => __( 'Content Alignment', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::CHOOSE,
			'options'   => array(
				'left'   => array( 'title' => __( 'Left', 'trustscript' ),   'icon' => 'eicon-text-align-left' ),
				'center' => array( 'title' => __( 'Center', 'trustscript' ), 'icon' => 'eicon-text-align-center' ),
				'right'  => array( 'title' => __( 'Right', 'trustscript' ),  'icon' => 'eicon-text-align-right' ),
			),
			'default'   => 'left',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-elementor-review-card' => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-card'         => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card'     => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-review-rating'        => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-review-author'        => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-meta' => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-text' => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-author' => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-product'      => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-date'         => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-text'         => 'text-align: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-meta'         => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-author'       => 'justify-content: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-author-info'  => 'text-align: {{VALUE}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Background::get_type(), array(
			'name'      => 'card_background',
			'label'     => __( 'Background', 'trustscript' ),
			'types'     => array( 'classic', 'gradient' ),
			'selector'  => '{{WRAPPER}} .trustscript-elementor-review-card, {{WRAPPER}} .trustscript-marquee-card, {{WRAPPER}} .trustscript-image-first-card',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Elementor control parameter, not WP_Query.
			'exclude'   => array( 'image', 'position', 'attachment', 'attachment_alert', 'repeat', 'size', 'video', 'bg_width' ),
			'fields_options' => array(
				'background' => array(
					'default' => 'classic',
				),
				'color' => array(
					'default' => '#ffffff',
				),
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'card_border',
			'selector' => '{{WRAPPER}} .trustscript-elementor-review-card, {{WRAPPER}} .trustscript-marquee-card, {{WRAPPER}} .trustscript-image-first-card',
		) );

		$this->add_control( 'card_border_radius', array(
			'label'      => __( 'Border Radius', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-elementor-review-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'     => 'card_shadow',
			'selector' => '{{WRAPPER}} .trustscript-elementor-review-card, {{WRAPPER}} .trustscript-marquee-card, {{WRAPPER}} .trustscript-image-first-card',
		) );

		$this->add_responsive_control( 'card_padding', array(
			'label'      => __( 'Padding', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em', '%' ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-elementor-review-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
		) );

		$this->add_control( 'card_hover_effects_heading', array(
			'label'     => __( 'Hover Effects', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		$this->add_responsive_control( 'card_hover_scale', array(
			'label'      => __( 'Scale', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( '%' ),
			'range'      => array( '%' => array( 'min' => 80, 'max' => 120, 'step' => 5 ) ),
			'default'    => array( 'unit' => '%', 'size' => 100 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-elementor-review-card:hover' => 'transform: scale(calc({{SIZE}}/100));',
				'{{WRAPPER}} .trustscript-marquee-card:hover' => 'transform: scale(calc({{SIZE}}/100));',
				'{{WRAPPER}} .trustscript-image-first-card:hover' => 'transform: scale(calc({{SIZE}}/100));',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array(
			'name'      => 'card_hover_shadow',
			'label'     => __( 'Hover Shadow', 'trustscript' ),
			'selector'  => '{{WRAPPER}} .trustscript-elementor-review-card:hover, {{WRAPPER}} .trustscript-marquee-card:hover, {{WRAPPER}} .trustscript-image-first-card:hover',
			'separator' => 'before',
		) );

		$this->add_responsive_control( 'card_transition_duration', array(
			'label'      => __( 'Transition Duration (ms)', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'ms' ),
			'range'      => array( 'ms' => array( 'min' => 100, 'max' => 1000, 'step' => 50 ) ),
			'default'    => array( 'unit' => 'ms', 'size' => 300 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-elementor-review-card' => 'transition: transform {{SIZE}}{{UNIT}}, box-shadow {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-card' => 'transition: transform {{SIZE}}{{UNIT}}, box-shadow {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card' => 'transition: transform {{SIZE}}{{UNIT}}, box-shadow {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'typography_style_section', array(
			'label' => __( 'Product Name', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'product_name_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array(
				'{{WRAPPER}} .trustscript-review-product' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-marquee-product' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-image-first-product' => 'color: {{VALUE}}',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'product_name_typography',
			'selector' => '{{WRAPPER}} .trustscript-review-product, {{WRAPPER}} .trustscript-marquee-product, {{WRAPPER}} .trustscript-image-first-product',
		) );

		$this->add_responsive_control( 'product_name_spacing', array(
			'label'      => __( 'Bottom Spacing', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 6 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-review-product' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-product' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-product' => 'margin-bottom: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'stars_style_section', array(
			'label' => __( 'Star Rating', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'star_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'default'   => '#fbbf24',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-stars' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-marquee-stars' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-image-first-card-rating' => 'color: {{VALUE}}',
			),
		) );

		$this->add_responsive_control( 'star_size', array(
			'label'      => __( 'Size', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 10, 'max' => 60 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 16 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-stars'                    => 'font-size: {{SIZE}}{{UNIT}}; line-height: 1;',
				'{{WRAPPER}} .trustscript-marquee-stars'            => 'font-size: {{SIZE}}{{UNIT}}; line-height: 1;',
				'{{WRAPPER}} .trustscript-image-first-card-rating'  => 'font-size: {{SIZE}}{{UNIT}}; line-height: 1;',
			),
		) );

		$this->add_responsive_control( 'stars_spacing', array(
			'label'      => __( 'Bottom Spacing', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 4 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-review-rating' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-meta' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card-meta' => 'margin-bottom: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();
		$this->start_controls_section( 'date_style_section', array(
			'label'     => __( 'Review Date', 'trustscript' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'layout' => 'marquee' ),
		) );

		$this->add_control( 'date_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'default'   => '#8b7355',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-marquee-date' => 'color: {{VALUE}}',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'date_typography',
			'selector' => '{{WRAPPER}} .trustscript-marquee-date',
		) );

		$this->add_responsive_control( 'date_spacing', array(
			'label'      => __( 'Bottom Spacing', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 16 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-marquee-date' => 'margin-bottom: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();

		$this->start_controls_section( 'badge_style_section', array(
			'label'     => __( 'Verified Badge', 'trustscript' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_verification_badge' => 'yes' ),
		) );

		$this->add_control( 'badge_style', array(
			'label'   => __( 'Badge Style', 'trustscript' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'inline',
			'options' => array(
				'inline' => __( 'Inline (next to stars)', 'trustscript' ),
			),
		) );


		$this->add_control( 'badge_text_color', array(
			'label'     => __( 'Text & Icon Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'default'   => '#ffffff',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-badge-inline'     => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-badge-inline svg' => 'stroke: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-verified' => 'color: {{VALUE}};',
			),
		) );

		$this->add_control( 'badge_bg_color', array(
			'label'     => __( 'Background Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'default'   => '#10b981',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-badge-inline' => 'background-color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-verified' => 'background-color: {{VALUE}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'      => 'badge_typography',
			'label'     => __( 'Typography', 'trustscript' ),
			'selector'  => '{{WRAPPER}} .trustscript-badge-inline',
			'condition' => array( 'badge_style' => 'inline' ),
		) );

		$this->add_responsive_control( 'badge_icon_size', array(
			'label'      => __( 'Icon Size', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 8, 'max' => 48 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 14 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-badge-inline svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
			),
			'condition'  => array( 'badge_style' => 'inline' ),
		) );

		$this->add_responsive_control( 'badge_font_size', array(
			'label'      => __( 'Text Size', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 8, 'max' => 24 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 11 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-badge-inline' => 'font-size: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-verified' => 'font-size: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->add_responsive_control( 'badge_padding', array(
			'label'      => __( 'Padding', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => array( 'px', 'em' ),
			'default'    => array( 'top' => '3', 'right' => '8', 'bottom' => '3', 'left' => '8', 'unit' => 'px' ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-badge-inline' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-verified' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			),
			'condition'  => array( 'badge_style' => 'inline' ),
		) );

		$this->add_responsive_control( 'badge_border_radius', array(
			'label'      => __( 'Border Radius', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 50 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 4 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-badge-inline' => 'border-radius: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-marquee-verified' => 'border-radius: {{SIZE}}{{UNIT}};',
			),
			'condition'  => array( 'badge_style' => 'inline' ),
		) );

		$this->add_responsive_control( 'badge_bottom_spacing', array(
			'label'      => __( 'Bottom Spacing', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 8 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-badge-wrap' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-badge-inline' => 'margin-bottom: {{SIZE}}{{UNIT}};',
			),
			'condition'  => array( 'badge_style' => 'inline' ),
		) );

		$this->end_controls_section();

		$this->start_controls_section( 'review_text_style_section', array(
			'label' => __( 'Review Text', 'trustscript' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		) );

		$this->add_control( 'review_text_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array(
				'{{WRAPPER}} .trustscript-review-text' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-marquee-text' => 'color: {{VALUE}}',
				'{{WRAPPER}} .trustscript-image-first-card-text' => 'color: {{VALUE}}',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'review_text_typography',
			'selector' => '{{WRAPPER}} .trustscript-review-text, {{WRAPPER}} .trustscript-marquee-text, {{WRAPPER}} .trustscript-image-first-card-text',
		) );

		$this->add_responsive_control( 'review_text_spacing', array(
			'label'      => __( 'Bottom Spacing', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 10 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-review-text' => 'margin-bottom: {{SIZE}}{{UNIT}} !important;',
				'{{WRAPPER}} .trustscript-marquee-text' => 'margin-bottom: {{SIZE}}{{UNIT}} !important;',
				'{{WRAPPER}} .trustscript-image-first-card-text' => 'margin-bottom: {{SIZE}}{{UNIT}} !important;',
			),
		) );

		$this->end_controls_section();

		$this->start_controls_section( 'author_style_section', array(
			'label'     => __( 'Customer Name', 'trustscript' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => array( 'show_customer_name' => 'yes' ),
		) );

		$this->add_control( 'author_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => array(
				'{{WRAPPER}} .trustscript-review-author-name'  => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-review-author-label' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-author' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-author-name' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-marquee-author-label' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-author-name' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-author-label' => 'color: {{VALUE}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'author_typography',
			'selector' => '{{WRAPPER}} .trustscript-review-author-name, {{WRAPPER}} .trustscript-marquee-author-name, {{WRAPPER}} .trustscript-image-first-card-author-name',
		) );

		$this->add_responsive_control( 'author_label_font_size', array(
			'label'      => __( 'Label Font Size', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 8, 'max' => 20 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 12 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-marquee-author-label' => 'font-size: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-review-author-label' => 'font-size: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card-author-label' => 'font-size: {{SIZE}}{{UNIT}};',
			),
			'separator'  => 'before',
		) );

		$this->add_control( 'avatar_heading', array(
			'label'     => __( 'Avatar', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		$this->add_group_control( \Elementor\Group_Control_Background::get_type(), array(
			'name'      => 'avatar_background',
			'label'     => __( 'Background', 'trustscript' ),
			'types'     => array( 'classic', 'gradient' ),
			'selector'  => '{{WRAPPER}} .trustscript-marquee-avatar, {{WRAPPER}} .trustscript-review-avatar, {{WRAPPER}} .trustscript-image-first-card-avatar',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Elementor control parameter, not WP_Query.
			'exclude'   => array( 'image', 'position', 'attachment', 'attachment_alert', 'repeat', 'size', 'video', 'bg_width' ),
		) );

		$this->add_responsive_control( 'avatar_size', array(
			'label'      => __( 'Size', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 20, 'max' => 100 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 42 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-marquee-avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-review-avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card-avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->add_responsive_control( 'avatar_border_radius', array(
			'label'      => __( 'Border Radius', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px', '%' ),
			'range'      => array(
				'px' => array( 'min' => 0, 'max' => 100 ),
				'%'  => array( 'min' => 0, 'max' => 100 ),
			),
			'default'    => array( 'unit' => '%', 'size' => 50 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-marquee-avatar' => 'border-radius: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-review-avatar' => 'border-radius: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card-avatar' => 'border-radius: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array(
			'name'     => 'avatar_border',
			'label'    => __( 'Border', 'trustscript' ),
			'selector' => '{{WRAPPER}} .trustscript-marquee-avatar, {{WRAPPER}} .trustscript-review-avatar, {{WRAPPER}} .trustscript-image-first-card-avatar',
		) );

		$this->add_control( 'avatar_text_heading', array(
			'label'     => __( 'Text (Initials)', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		) );

		$this->add_control( 'avatar_text_color', array(
			'label'     => __( 'Color', 'trustscript' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'default'   => '#ffffff',
			'selectors' => array(
				'{{WRAPPER}} .trustscript-marquee-avatar' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-review-avatar' => 'color: {{VALUE}};',
				'{{WRAPPER}} .trustscript-image-first-card-avatar' => 'color: {{VALUE}};',
			),
		) );

		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array(
			'name'     => 'avatar_text_typography',
			'label'    => __( 'Typography', 'trustscript' ),
			'selector' => '{{WRAPPER}} .trustscript-marquee-avatar, {{WRAPPER}} .trustscript-review-avatar, {{WRAPPER}} .trustscript-image-first-card-avatar',
		) );

		$this->add_responsive_control( 'avatar_name_gap', array(
			'label'      => __( 'Gap to Name', 'trustscript' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
			'default'    => array( 'unit' => 'px', 'size' => 12 ),
			'selectors'  => array(
				'{{WRAPPER}} .trustscript-marquee-avatar' => 'margin-right: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-review-avatar' => 'margin-right: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .trustscript-image-first-card-avatar' => 'margin-right: {{SIZE}}{{UNIT}};',
			),
		) );

		$this->end_controls_section();
	}

	private function get_reviews_for_selection() {
		$options = array();
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$reviews = get_comments( array( 'type' => 'review', 'status' => 'approve', 'number' => 100, 'meta_key' => '_trustscript_review_token' ) );
		foreach ( $reviews as $review ) {
			$product      = wc_get_product( $review->comment_post_ID );
			$product_name = $product ? $product->get_name() : __( 'Unknown Product', 'trustscript' );
			$rating       = get_comment_meta( $review->comment_ID, 'rating', true );
			$options[ $review->comment_ID ] = sprintf(
				'%s - %s (%s) - %s',
				str_repeat( '★', intval( $rating ) ),
				$product_name,
				$review->comment_author,
				wp_trim_words( $review->comment_content, 10 )
			);
		}
		return $options;
	}

	private function get_reviews( $settings ) {
		if ( 'manual' === $settings['selection_method'] && ! empty( $settings['selected_reviews'] ) ) {
			$comment_ids = array_map( 'intval', $settings['selected_reviews'] );
			$reviews = get_comments( array(
				'comment__in' => $comment_ids,
				'status'      => 'approve',
				'type'        => 'review',
			) );
			foreach ( $reviews as $review ) {
				$review->rating           = (int) get_comment_meta( $review->comment_ID, 'rating', true );
				$review->trustscript_hash = get_comment_meta( $review->comment_ID, '_trustscript_verification_hash', true );
				$review->verified         = ! empty( $review->trustscript_hash );
				$review->product_name     = get_the_title( $review->comment_post_ID );
			}
			return $reviews;
		}

		$sort_by     = 'recent';
		$product_ids = array();
		$category_id = 0;

		switch ( $settings['selection_method'] ) {
			case 'highest_rated':
				$sort_by = 'highest_rated';
				break;
			case 'category':
				if ( ! empty( $settings['product_category'] ) ) {
					$category_id = intval( $settings['product_category'] );
				}
				break;
		}

		return TrustScript_Review_Query::get_reviews( array(
			'max_reviews' => intval( $settings['total_reviews'] ?? 6 ),
			'min_rating'  => intval( $settings['minimum_rating'] ?? 1 ),
			'source_type' => $category_id > 0 ? 'category' : 'all',
			'product_ids' => $product_ids,
			'category_id' => $category_id,
			'sort_by'     => $sort_by,
		) );
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$reviews  = $this->get_reviews( $settings );

		if ( empty( $reviews ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="trustscript-elementor-alert trustscript-elementor-alert-info">' . esc_html__( 'No reviews found matching your criteria.', 'trustscript' ) . '</div>';
			}
			return;
		}

		$is_marquee      = 'marquee' === $settings['layout'];
		$is_image_first  = 'image-first' === $settings['layout'];
		$uid             = 'trustscript-showcase-' . $this->get_id();
		$show_badge      = 'yes' === $settings['show_verification_badge'];
		$badge_label     = __( 'Verified', 'trustscript' );

		if ( $is_image_first && 'yes' === ( $settings['images_only'] ?? '' ) ) {
			$reviews = array_filter( $reviews, function( $review ) {
				$media_urls_json = get_comment_meta( $review->comment_ID, '_trustscript_media_urls', true );
				return ! empty( $media_urls_json );
			} );
			
			if ( empty( $reviews ) ) {
				if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
					echo '<div class="trustscript-elementor-alert trustscript-elementor-alert-info">' . esc_html__( 'No reviews with images found matching your criteria.', 'trustscript' ) . '</div>';
				}
				return;
			}
		}?>
		<style>
			#<?php echo esc_attr( $uid ); ?> .trustscript-elementor-review-card {
				display: flex; flex-direction: column; height: 100%; position: relative; transition: transform 0.3s ease, box-shadow 0.3s ease;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-marquee-card {
				display: flex; flex-direction: column; position: relative; transition: transform 0.3s ease, box-shadow 0.3s ease;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card {
				display: flex; flex-direction: column; height: 100%; position: relative;
				overflow: hidden; 
				isolation: isolate;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-image-wrapper {
				position: relative; overflow: hidden; background: #f5f5f5; flex-shrink: 0;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-image {
				width: 100%; display: block;
				transition: transform 0.55s cubic-bezier(0.4, 0, 0.2, 1);
				will-change: transform;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card:hover .trustscript-image-first-card-image {
				transform: scale(1.05);
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-overlay {
				position: absolute; top: 0; left: 0; right: 0; bottom: 0;
				background: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.32) 100%);
				opacity: 0; transition: opacity 0.4s ease; pointer-events: none;
			}
			<?php if ( 'yes' === ( $settings['image_hover_overlay'] ?? 'yes' ) ) : ?>
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card:hover .trustscript-image-first-card-overlay {
				opacity: 1;
			}
			<?php endif; ?>
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-content {
				flex-grow: 1; display: flex; flex-direction: column; padding: 1.25rem;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-meta {
				display: flex; align-items: center; flex-wrap: wrap; gap: 8px; line-height: 1;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-rating {
				display: inline-flex; align-items: center; gap: 4px;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-text {
				font-size: 0.95rem; flex-grow: 1;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-author {
				font-weight: 500; color: #333; display: flex; align-items: center;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-avatar {
				flex-shrink: 0; display: flex; align-items: center; justify-content: center;
			}
			.trustscript-image-first-card-avatar {
				background-color: var(--avatar-bg, #ccc);
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-author-info {
				text-align: left;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-image-first-card-author-name {
				/* no flex-grow */
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-text { flex-grow: 1; }
			#<?php echo esc_attr( $uid ); ?> .trustscript-marquee-text { flex-grow: 1; }
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-author {
				display: flex;
				align-items: center;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-avatar {
				flex-shrink: 0; display: flex; align-items: center; justify-content: center;
			}
			.trustscript-review-avatar {
				background-color: var(--avatar-bg, #ccc);
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-author-info {
				text-align: left;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-author-name {
				/* no flex-grow */
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-marquee-author { 
				display: flex;
				align-items: center;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-marquee-author-info {
				text-align: left;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-review-rating {
				display: flex;
				align-items: center;
				flex-wrap: nowrap;
				line-height: 1;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-stars {
				display: inline-flex;
				align-items: center;
				line-height: 1;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-badge-wrap,
			#<?php echo esc_attr( $uid ); ?> .trustscript-badge-inline {
				display: inline-flex;
				align-items: center;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-badge-inline svg {
				vertical-align: middle;
				flex-shrink: 0;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-marquee-avatar {
				display: flex;
				align-items: center;
				justify-content: center;
				flex-shrink: 0;
			}
			#<?php echo esc_attr( $uid ); ?> .trustscript-star-empty {
				color: #d1d5db !important;
			}
		</style>
		<?php

		if ( ! $is_marquee ) {
			$cols     = isset( $settings['columns'] )        ? intval( $settings['columns'] )        : 3;
			$cols_tab = isset( $settings['columns_tablet'] ) ? intval( $settings['columns_tablet'] ) : 2;
			$cols_mob = isset( $settings['columns_mobile'] ) ? intval( $settings['columns_mobile'] ) : 1;
			?>
			<style>
				#<?php echo esc_attr( $uid ); ?> { display: grid; grid-template-columns: repeat(<?php echo esc_attr( $cols ); ?>, 1fr); }
				@media (max-width: 1024px) { #<?php echo esc_attr( $uid ); ?> { grid-template-columns: repeat(<?php echo esc_attr( $cols_tab ); ?>, 1fr); } }
				@media (max-width:  767px) { #<?php echo esc_attr( $uid ); ?> { grid-template-columns: repeat(<?php echo esc_attr( $cols_mob ); ?>, 1fr); } }
			</style>
			<div id="<?php echo esc_attr( $uid ); ?>" class="trustscript-reviews-showcase <?php echo $is_image_first ? 'trustscript-reviews-image-first' : 'trustscript-reviews-grid'; ?>">
		<?php } else {
			$speed      = isset( $settings['marquee_speed'] )      ? intval( $settings['marquee_speed'] )      : 32;
			$pause      = 'yes' === ( $settings['marquee_pause_hover'] ?? 'yes' );
			$direction  = isset( $settings['marquee_direction'] )  ? sanitize_key( $settings['marquee_direction'] ) : 'left';
			$card_width = isset( $settings['marquee_card_width'] ) ? intval( $settings['marquee_card_width'] )  : 320;
			$gap        = isset( $settings['marquee_gap'] )        ? intval( $settings['marquee_gap'] )         : 24;
			$config_json = wp_json_encode( array(
				'speed'       => $speed,
				'pauseOnHover'=> $pause,
				'direction'   => $direction,
				'cardWidth'   => $card_width,
				'gap'         => $gap,
			) );
			?>
			<div class="trustscript-marquee-slider"
				 id="<?php echo esc_attr( $uid ); ?>"
				 data-direction="<?php echo esc_attr( $direction ); ?>"
				 data-config='<?php echo esc_attr( $config_json ); ?>'>
				<div class="trustscript-marquee-track" style="animation-duration:<?php echo esc_attr( $speed ); ?>s; animation-name:<?php echo 'right' === $direction ? 'trustscript-marquee-rtl' : 'trustscript-marquee'; ?>;">
		<?php } ?>

		<?php 
		$display_reviews = $is_marquee ? array_merge( $reviews, $reviews ) : $reviews;
		foreach ( $display_reviews as $review ) :
			$product           = wc_get_product( $review->comment_post_ID );
			$rating            = $review->rating;
			$verification_hash = $review->trustscript_hash;
			$review_text       = wp_trim_words( $review->comment_content, $settings['excerpt_length'], '...' );
			
			if ( $is_image_first ) {
				$card_class = 'trustscript-image-first-card';
				$media_urls_json = get_comment_meta( $review->comment_ID, '_trustscript_media_urls', true );
				$media_urls      = ! empty( $media_urls_json ) ? json_decode( $media_urls_json, true ) : array();
				$first_image     = ! empty( $media_urls ) ? reset( $media_urls ) : null;
			} else {
				$card_class = $is_marquee ? 'trustscript-marquee-card' : 'trustscript-elementor-review-card';
			}
		?>
			<div class="<?php echo esc_attr( $card_class ); ?>">

				<?php if ( $is_image_first && ! empty( $first_image ) ) : ?>
					<div class="trustscript-image-first-card-image-wrapper">
						<img src="<?php echo esc_url( TrustScript_Review_Renderer::normalize_media_url( $first_image ) ); ?>" 
							 alt="<?php echo esc_attr( __( 'Review photo', 'trustscript' ) ); ?>" 
							 class="trustscript-image-first-card-image"
							 loading="lazy"
							 decoding="async"
							 onload="this.classList.add('ts-loaded')"
							 onerror="this.classList.add('ts-loaded')">
						<div class="trustscript-image-first-card-overlay"></div>
					</div>
					
					<div class="trustscript-image-first-card-content">
						<?php if ( 'yes' === $settings['show_product_name'] && $product ) : ?>
							<?php
							$product_name = $product->get_name();
							$max_length = isset( $settings['product_name_length'] ) ? intval( $settings['product_name_length'] ) : 50;
							if ( strlen( $product_name ) > $max_length ) {
								$product_name = substr( $product_name, 0, $max_length );
							}
							?>
							<div class="trustscript-image-first-product">
								<?php echo esc_html( $product_name ); ?>
							</div>
						<?php endif; ?>
						
						<div class="trustscript-image-first-card-meta">
							<div class="trustscript-image-first-card-rating">
								<?php echo wp_kses_post( TrustScript_Review_Renderer::render_stars( intval( $rating ) ) ); ?>
							</div>
							
							<?php if ( $show_badge && ! empty( $verification_hash ) ) : ?>
								<span class="trustscript-badge-inline" style="display:inline-flex; align-items:center; gap:4px; font-size:0.85rem;">
									<svg xmlns="http://www.w3.org/2000/svg"
										 width="1em" height="1em"
										 viewBox="0 0 24 24"
										 fill="none"
										 stroke="currentColor"
										 stroke-width="2"
										 stroke-linecap="round"
										 stroke-linejoin="round"
										 aria-hidden="true"
										 style="flex-shrink:0;">
										<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
										<path d="m9 12 2 2 4-4"/>
									</svg>
									<?php echo esc_html( $badge_label ); ?>
								</span>
							<?php endif; ?>
						</div>
						
						<div class="trustscript-image-first-card-text">
							"<?php echo wp_kses_post( $review_text ); ?>"
						</div>
						
						<?php if ( 'yes' === $settings['show_customer_name'] ) : ?>
							<div class="trustscript-image-first-card-divider"></div>
							<div class="trustscript-image-first-card-author">
								<?php
								$initials = implode( '', array_map( function($w) { return substr($w, 0, 1); }, explode( ' ', $review->comment_author ) ) );
								$colors   = array( '#c8813a', '#8fa8c2', '#7a9e7e', '#b07cc6', '#c97b7b', '#d4a24e' );
								$color    = $colors[ abs( crc32( $review->comment_author ) ) % count( $colors ) ];
								?>
								<div class="trustscript-image-first-card-avatar" style="--avatar-bg: <?php echo esc_attr( $color ); ?>;">
									<?php echo esc_html( $initials ); ?>
								</div>
								<div class="trustscript-image-first-card-author-info">
									<div class="trustscript-image-first-card-author-name"><?php echo esc_html( $review->comment_author ); ?></div>
									<?php if ( 'yes' === $settings['show_customer_label'] ?? 'yes' ) : ?>
										<div class="trustscript-image-first-card-author-label"><?php esc_html_e( 'Verified Buyer', 'trustscript' ); ?></div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php else :
					if ( 'yes' === $settings['show_product_name'] && $product ) :
						$product_name = $product->get_name();
						$max_length = isset( $settings['product_name_length'] ) ? intval( $settings['product_name_length'] ) : 50;
						if ( strlen( $product_name ) > $max_length ) {
							$product_name = substr( $product_name, 0, $max_length );
						}
					?>
						<div class="<?php echo $is_marquee ? 'trustscript-marquee-product' : 'trustscript-review-product'; ?>">
							<?php echo esc_html( $product_name ); ?>
						</div>
					<?php endif; ?>

					<div class="<?php echo $is_marquee ? 'trustscript-marquee-meta' : 'trustscript-review-rating'; ?>" style="display:flex; align-items:center; flex-wrap:nowrap; gap:6px; line-height:1;">
						<div class="<?php echo $is_marquee ? 'trustscript-marquee-stars' : 'trustscript-stars'; ?>">
							<?php echo wp_kses_post( TrustScript_Review_Renderer::render_stars( intval( $rating ) ) ); ?>
						</div>

						<?php if ( $show_badge && ! empty( $verification_hash ) ) : ?>
							<span class="<?php echo $is_marquee ? 'trustscript-marquee-verified' : 'trustscript-badge-wrap'; ?>" style="display:inline-flex; align-items:center;">
								<?php if ( ! $is_marquee ) : ?>
								<span class="trustscript-badge-inline" style="display:inline-flex; align-items:center; gap:4px;">
									<svg xmlns="http://www.w3.org/2000/svg"
										 width="1em" height="1em"
										 viewBox="0 0 24 24"
										 fill="none"
										 stroke="currentColor"
										 stroke-width="2"
										 stroke-linecap="round"
										 stroke-linejoin="round"
										 aria-hidden="true"
										 style="flex-shrink:0;">
										<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
										<path d="m9 12 2 2 4-4"/>
									</svg>
									<?php echo esc_html( $badge_label ); ?>
								</span>
								<?php else : ?>
									✓ <?php echo esc_html( $badge_label ); ?>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>

					<?php if ( $is_marquee ) : ?>
						<div class="trustscript-marquee-date">
							<?php echo esc_html( TrustScript_Date_Formatter::format( $review->comment_date, 'short' ) ); ?>
						</div>
					<?php endif; ?>

					<div class="<?php echo $is_marquee ? 'trustscript-marquee-text' : 'trustscript-review-text'; ?>">
						<?php echo wp_kses_post( $review_text ); ?>
					</div>

					<?php if ( $is_marquee ) : ?>
						<div class="trustscript-flex-spacer"></div>
						<div class="trustscript-marquee-divider"></div>
					<?php endif; ?>

					<?php if ( 'yes' === $settings['show_customer_name'] ) : ?>
						<?php if ( $is_marquee ) : ?>
							<div class="trustscript-marquee-author">
								<?php
								$initials = implode( '', array_map( function($w) { return substr($w, 0, 1); }, explode( ' ', $review->comment_author ) ) );
								$colors   = array( '#c8813a', '#8fa8c2', '#7a9e7e', '#b07cc6', '#c97b7b', '#d4a24e' );
								$color    = $colors[ abs( crc32( $review->comment_author ) ) % count( $colors ) ];
								?>
								<div class="trustscript-marquee-avatar">
									<?php echo esc_html( $initials ); ?>
								</div>
								<div class="trustscript-marquee-author-info">
									<div class="trustscript-marquee-author-name"><?php echo esc_html( $review->comment_author ); ?></div>
									<div class="trustscript-marquee-author-label"><?php esc_html_e( 'Verified Buyer', 'trustscript' ); ?></div>
								</div>
							</div>
						<?php else : ?>
							<div class="trustscript-review-divider"></div>
							<div class="trustscript-review-author">
								<?php
								$initials = implode( '', array_map( function($w) { return substr($w, 0, 1); }, explode( ' ', $review->comment_author ) ) );
								$colors   = array( '#c8813a', '#8fa8c2', '#7a9e7e', '#b07cc6', '#c97b7b', '#d4a24e' );
								$color    = $colors[ abs( crc32( $review->comment_author ) ) % count( $colors ) ];
								?>
								<div class="trustscript-review-avatar" style="--avatar-bg: <?php echo esc_attr( $color ); ?>;">
									<?php echo esc_html( $initials ); ?>
								</div>
								<div class="trustscript-review-author-info">
									<div class="trustscript-review-author-name"><?php echo esc_html( $review->comment_author ); ?></div>
									<?php if ( 'yes' === $settings['show_customer_label'] ?? 'yes' ) : ?>
										<div class="trustscript-review-author-label"><?php esc_html_e( 'Verified Buyer', 'trustscript' ); ?></div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

			</div>
		<?php endforeach; ?>

		<?php if ( $is_marquee ) : ?>
				</div><?php ?>
			</div><?php ?>
		<?php else : ?>
			</div><?php ?>
		<?php endif; ?>
		<?php
	}
}