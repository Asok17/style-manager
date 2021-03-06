<?php

/**
 * Class StyleManager_Customize_Color_Control.
 *
 * A simple color control.
 */
class StyleManager_Customize_Color_Control extends StyleManager_Customize_Control {
	public $type = 'color';

	/**
	 * Render the control's content.
	 *
	 * @since 1.0.0
	 */
	public function render_content() { ?>
		<label>
			<?php if ( ! empty( $this->label ) ) : ?>
				<span class="customize-control-title"><?php echo esc_html( $this->label ); ?></span>
			<?php endif; ?>
			<input type="<?php echo esc_attr( $this->type ); ?>" <?php $this->input_attrs(); ?> value="<?php echo esc_attr( $this->value() ); ?>" <?php $this->link(); ?> />
			<?php if ( ! empty( $this->description ) ) : ?>
				<span class="description customize-control-description"><?php echo $this->description; ?></span>
			<?php endif; ?>
		</label>
	<?php

	}
}
