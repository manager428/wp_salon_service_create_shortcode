<?php
/**
 * WordPress API for creating bbcode-like tags or what WordPress calls
 * "shortcodes". The tag and attribute parsing or regular expression code is
 * based on the Textpattern tag parser.
 *
 * A few examples are below:
 *
 * [shortcode /]
 * [shortcode foo="bar" baz="bing" /]
 * [shortcode foo="bar"]content[/shortcode]
 *
 * Shortcode tags support attributes and enclosed content, but does not entirely
 * support inline shortcodes in other shortcodes. You will have to call the
 * shortcode parser in your function to account for that.
 *
 * {@internal
 * Please be aware that the above note was made during the beta of WordPress 2.6
 * and in the future may not be accurate. Please update the note when it is no
 * longer the case.}}
 *
 * To apply shortcode tags to content:
 *
 *     $out = do_shortcode( $content );
 *
 * @link https://developer.wordpress.org/plugins/shortcodes/
 *
 * @package WordPress
 * @subpackage Shortcodes
 * @since 2.5.0
 */

/**
 * Container for storing shortcode tags and their hook to call for the shortcode.
 *
 * @since 2.5.0
 *
 * @name $shortcode_tags
 * @var array
 * @global array $shortcode_tags
 */
$shortcode_tags = array();

/**
 * Adds a new shortcode.
 *
 * Care should be taken through prefixing or other means to ensure that the
 * shortcode tag being added is unique and will not conflict with other,
 * already-added shortcode tags. In the event of a duplicated tag, the tag
 * loaded last will take precedence.
 *
 * @since 2.5.0
 *
 * @global array $shortcode_tags
 *
 * @param string   $tag      Shortcode tag to be searched in post content.
 * @param callable $callback The callback function to run when the shortcode is found.
 *                           Every shortcode callback is passed three parameters by default,
 *                           including an array of attributes (`$atts`), the shortcode content
 *                           or null if not set (`$content`), and finally the shortcode tag
 *                           itself (`$shortcode_tag`), in that order.
 */
function add_shortcode( $tag, $callback ) {
	global $shortcode_tags;

	if ( '' === trim( $tag ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			__( 'Invalid shortcode name: Empty name given.' ),
			'4.4.0'
		);
		return;
	}

	if ( 0 !== preg_match( '@[<>&/\[\]\x00-\x20=]@', $tag ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: 1: Shortcode name, 2: Space-separated list of reserved characters. */
				__( 'Invalid shortcode name: %1$s. Do not use spaces or reserved characters: %2$s' ),
				$tag,
				'& / < > [ ] ='
			),
			'4.4.0'
		);
		return;
	}

	$shortcode_tags[ $tag ] = $callback;
}

/**
 * Removes hook for shortcode.
 *
 * @since 2.5.0
 *
 * @global array $shortcode_tags
 *
 * @param string $tag Shortcode tag to remove hook for.
 */
function remove_shortcode( $tag ) {
	global $shortcode_tags;

	unset( $shortcode_tags[ $tag ] );
}

/**
 * Clears all shortcodes.
 *
 * This function clears all of the shortcode tags by replacing the shortcodes global with
 * an empty array. This is actually an efficient method for removing all shortcodes.
 *
 * @since 2.5.0
 *
 * @global array $shortcode_tags
 */
function remove_all_shortcodes() {
	global $shortcode_tags;

	$shortcode_tags = array();
}

/**
 * Determines whether a registered shortcode exists named $tag.
 *
 * @since 3.6.0
 *
 * @global array $shortcode_tags List of shortcode tags and their callback hooks.
 *
 * @param string $tag Shortcode tag to check.
 * @return bool Whether the given shortcode exists.
 */
function shortcode_exists( $tag ) {
	global $shortcode_tags;
	return array_key_exists( $tag, $shortcode_tags );
}

/**
 * Determines whether the passed content contains the specified shortcode.
 *
 * @since 3.6.0
 *
 * @global array $shortcode_tags
 *
 * @param string $content Content to search for shortcodes.
 * @param string $tag     Shortcode tag to check.
 * @return bool Whether the passed content contains the given shortcode.
 */
function has_shortcode( $content, $tag ) {
	if ( false === strpos( $content, '[' ) ) {
		return false;
	}

	if ( shortcode_exists( $tag ) ) {
		preg_match_all( '/' . get_shortcode_regex() . '/', $content, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return false;
		}

		foreach ( $matches as $shortcode ) {
			if ( $tag === $shortcode[2] ) {
				return true;
			} elseif ( ! empty( $shortcode[5] ) && has_shortcode( $shortcode[5], $tag ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Searches content for shortcodes and filter shortcodes through their hooks.
 *
 * This function is an alias for do_shortcode().
 *
 * @since 5.4.0
 *
 * @see do_shortcode()
 *
 * @param string $content     Content to search for shortcodes.
 * @param bool   $ignore_html When true, shortcodes inside HTML elements will be skipped.
 *                            Default false.
 * @return string Content with shortcodes filtered out.
 */
function apply_shortcodes( $content, $ignore_html = false ) {
	return do_shortcode( $content, $ignore_html );
}

/**
 * Searches content for shortcodes and filter shortcodes through their hooks.
 *
 * If there are no shortcode tags defined, then the content will be returned
 * without any filtering. This might cause issues when plugins are disabled but
 * the shortcode will still show up in the post or content.
 *
 * @since 2.5.0
 *
 * @global array $shortcode_tags List of shortcode tags and their callback hooks.
 *
 * @param string $content     Content to search for shortcodes.
 * @param bool   $ignore_html When true, shortcodes inside HTML elements will be skipped.
 *                            Default false.
 * @return string Content with shortcodes filtered out.
 */
function do_shortcode( $content, $ignore_html = false ) {
	global $shortcode_tags;

	if ( false === strpos( $content, '[' ) ) {
		return $content;
	}

	if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
		return $content;
	}

	// Find all registered tag names in $content.
	preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches );
	$tagnames = array_intersect( array_keys( $shortcode_tags ), $matches[1] );

	if ( empty( $tagnames ) ) {
		return $content;
	}

	$content = do_shortcodes_in_html_tags( $content, $ignore_html, $tagnames );

	$pattern = get_shortcode_regex( $tagnames );
	$content = preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $content );

	// Always restore square braces so we don't break things like <!--[if IE ]>.
	$content = unescape_invalid_shortcodes( $content );

	return $content;
}

/**
 * Retrieves the shortcode regular expression for searching.
 *
 * The regular expression combines the shortcode tags in the regular expression
 * in a regex class.
 *
 * The regular expression contains 6 different sub matches to help with parsing.
 *
 * 1 - An extra [ to allow for escaping shortcodes with double [[]]
 * 2 - The shortcode name
 * 3 - The shortcode argument list
 * 4 - The self closing /
 * 5 - The content of a shortcode when it wraps some content.
 * 6 - An extra ] to allow for escaping shortcodes with double [[]]
 *
 * @since 2.5.0
 * @since 4.4.0 Added the `$tagnames` parameter.
 *
 * @global array $shortcode_tags
 *
 * @param array $tagnames Optional. List of shortcodes to find. Defaults to all registered shortcodes.
 * @return string The shortcode search regular expression
 */
function get_shortcode_regex( $tagnames = null ) {
	global $shortcode_tags;

	if ( empty( $tagnames ) ) {
		$tagnames = array_keys( $shortcode_tags );
	}
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

	// WARNING! Do not change this regex without changing do_shortcode_tag() and strip_shortcode_tag().
	// Also, see shortcode_unautop() and shortcode.js.

	// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
	return '\\['                             // Opening bracket.
		. '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
		. "($tagregexp)"                     // 2: Shortcode name.
		. '(?![\\w-])'                       // Not followed by word character or hyphen.
		. '('                                // 3: Unroll the loop: Inside the opening shortcode tag.
		.     '[^\\]\\/]*'                   // Not a closing bracket or forward slash.
		.     '(?:'
		.         '\\/(?!\\])'               // A forward slash not followed by a closing bracket.
		.         '[^\\]\\/]*'               // Not a closing bracket or forward slash.
		.     ')*?'
		. ')'
		. '(?:'
		.     '(\\/)'                        // 4: Self closing tag...
		.     '\\]'                          // ...and closing bracket.
		. '|'
		.     '\\]'                          // Closing bracket.
		.     '(?:'
		.         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
		.             '[^\\[]*+'             // Not an opening bracket.
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
		.                 '[^\\[]*+'         // Not an opening bracket.
		.             ')*+'
		.         ')'
		.         '\\[\\/\\2\\]'             // Closing shortcode tag.
		.     ')?'
		. ')'
		. '(\\]?)';                          // 6: Optional second closing brocket for escaping shortcodes: [[tag]].
	// phpcs:enable
}

/**
 * Regular Expression callable for do_shortcode() for calling shortcode hook.
 *
 * @see get_shortcode_regex() for details of the match array contents.
 *
 * @since 2.5.0
 * @access private
 *
 * @global array $shortcode_tags
 *
 * @param array $m Regular expression match array.
 * @return string|false Shortcode output on success, false on failure.
 */
function do_shortcode_tag( $m ) {
	global $shortcode_tags;

	// Allow [[foo]] syntax for escaping a tag.
	if ( '[' === $m[1] && ']' === $m[6] ) {
		return substr( $m[0], 1, -1 );
	}

	$tag  = $m[2];
	$attr = shortcode_parse_atts( $m[3] );

	if ( ! is_callable( $shortcode_tags[ $tag ] ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			/* translators: %s: Shortcode tag. */
			sprintf( __( 'Attempting to parse a shortcode without a valid callback: %s' ), $tag ),
			'4.3.0'
		);
		return $m[0];
	}

	/**
	 * Filters whether to call a shortcode callback.
	 *
	 * Returning a non-false value from filter will short-circuit the
	 * shortcode generation process, returning that value instead.
	 *
	 * @since 4.7.0
	 *
	 * @param false|string $return Short-circuit return value. Either false or the value to replace the shortcode with.
	 * @param string       $tag    Shortcode name.
	 * @param array|string $attr   Shortcode attributes array or empty string.
	 * @param array        $m      Regular expression match array.
	 */
	$return = apply_filters( 'pre_do_shortcode_tag', false, $tag, $attr, $m );
	if ( false !== $return ) {
		return $return;
	}

	$content = isset( $m[5] ) ? $m[5] : null;

	$output = $m[1] . call_user_func( $shortcode_tags[ $tag ], $attr, $content, $tag ) . $m[6];

	/**
	 * Filters the output created by a shortcode callback.
	 *
	 * @since 4.7.0
	 *
	 * @param string       $output Shortcode output.
	 * @param string       $tag    Shortcode name.
	 * @param array|string $attr   Shortcode attributes array or empty string.
	 * @param array        $m      Regular expression match array.
	 */
	return apply_filters( 'do_shortcode_tag', $output, $tag, $attr, $m );
}

/**
 * Searches only inside HTML elements for shortcodes and process them.
 *
 * Any [ or ] characters remaining inside elements will be HTML encoded
 * to prevent interference with shortcodes that are outside the elements.
 * Assumes $content processed by KSES already.  Users with unfiltered_html
 * capability may get unexpected output if angle braces are nested in tags.
 *
 * @since 4.2.3
 *
 * @param string $content     Content to search for shortcodes.
 * @param bool   $ignore_html When true, all square braces inside elements will be encoded.
 * @param array  $tagnames    List of shortcodes to find.
 * @return string Content with shortcodes filtered out.
 */
function do_shortcodes_in_html_tags( $content, $ignore_html, $tagnames ) {
	// Normalize entities in unfiltered HTML before adding placeholders.
	$trans   = array(
		'&#91;' => '&#091;',
		'&#93;' => '&#093;',
	);
	$content = strtr( $content, $trans );
	$trans   = array(
		'[' => '&#91;',
		']' => '&#93;',
	);

	$pattern = get_shortcode_regex( $tagnames );
	$textarr = wp_html_split( $content );

	foreach ( $textarr as &$element ) {
		if ( '' === $element || '<' !== $element[0] ) {
			continue;
		}

		$noopen  = false === strpos( $element, '[' );
		$noclose = false === strpos( $element, ']' );
		if ( $noopen || $noclose ) {
			// This element does not contain shortcodes.
			if ( $noopen xor $noclose ) {
				// Need to encode stray '[' or ']' chars.
				$element = strtr( $element, $trans );
			}
			continue;
		}

		if ( $ignore_html || '<!--' === substr( $element, 0, 4 ) || '<![CDATA[' === substr( $element, 0, 9 ) ) {
			// Encode all '[' and ']' chars.
			$element = strtr( $element, $trans );
			continue;
		}

		$attributes = wp_kses_attr_parse( $element );
		if ( false === $attributes ) {
			// Some plugins are doing things like [name] <[email]>.
			if ( 1 === preg_match( '%^<\s*\[\[?[^\[\]]+\]%', $element ) ) {
				$element = preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $element );
			}

			// Looks like we found some crazy unfiltered HTML. Skipping it for sanity.
			$element = strtr( $element, $trans );
			continue;
		}

		// Get element name.
		$front   = array_shift( $attributes );
		$back    = array_pop( $attributes );
		$matches = array();
		preg_match( '%[a-zA-Z0-9]+%', $front, $matches );
		$elname = $matches[0];

		// Look for shortcodes in each attribute separately.
		foreach ( $attributes as &$attr ) {
			$open  = strpos( $attr, '[' );
			$close = strpos( $attr, ']' );
			if ( false === $open || false === $close ) {
				continue; // Go to next attribute. Square braces will be escaped at end of loop.
			}
			$double = strpos( $attr, '"' );
			$single = strpos( $attr, "'" );
			if ( ( false === $single || $open < $single ) && ( false === $double || $open < $double ) ) {
				/*
				 * $attr like '[shortcode]' or 'name = [shortcode]' implies unfiltered_html.
				 * In this specific situation we assume KSES did not run because the input
				 * was written by an administrator, so we should avoid changing the output
				 * and we do not need to run KSES here.
				 */
				$attr = preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $attr );
			} else {
				// $attr like 'name = "[shortcode]"' or "name = '[shortcode]'".
				// We do not know if $content was unfiltered. Assume KSES ran before shortcodes.
				$count    = 0;
				$new_attr = preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $attr, -1, $count );
				if ( $count > 0 ) {
					// Sanitize the shortcode output using KSES.
					$new_attr = wp_kses_one_attr( $new_attr, $elname );
					if ( '' !== trim( $new_attr ) ) {
						// The shortcode is safe to use now.
						$attr = $new_attr;
					}
				}
			}
		}
		$element = $front . implode( '', $attributes ) . $back;

		// Now encode any remaining '[' or ']' chars.
		$element = strtr( $element, $trans );
	}

	$content = implode( '', $textarr );

	return $content;
}

/**
 * Removes placeholders added by do_shortcodes_in_html_tags().
 *
 * @since 4.2.3
 *
 * @param string $content Content to search for placeholders.
 * @return string Content with placeholders removed.
 */
function unescape_invalid_shortcodes( $content ) {
	// Clean up entire string, avoids re-parsing HTML.
	$trans = array(
		'&#91;' => '[',
		'&#93;' => ']',
	);

	$content = strtr( $content, $trans );

	return $content;
}

/**
 * Retrieves the shortcode attributes regex.
 *
 * @since 4.4.0
 *
 * @return string The shortcode attribute regular expression.
 */
function get_shortcode_atts_regex() {
	return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
}

/**
 * Retrieves all attributes from the shortcodes tag.
 *
 * The attributes list has the attribute name as the key and the value of the
 * attribute as the value in the key/value pair. This allows for easier
 * retrieval of the attributes, since all attributes have to be known.
 *
 * @since 2.5.0
 *
 * @param string $text
 * @return array|string List of attribute values.
 *                      Returns empty array if '""' === trim( $text ).
 *                      Returns empty string if '' === trim( $text ).
 *                      All other matches are checked for not empty().
 */
function shortcode_parse_atts( $text ) {
	$atts    = array();
	$pattern = get_shortcode_atts_regex();
	$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );
	if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
		foreach ( $match as $m ) {
			if ( ! empty( $m[1] ) ) {
				$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
			} elseif ( ! empty( $m[3] ) ) {
				$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
			} elseif ( ! empty( $m[5] ) ) {
				$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
			} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
				$atts[] = stripcslashes( $m[7] );
			} elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
				$atts[] = stripcslashes( $m[8] );
			} elseif ( isset( $m[9] ) ) {
				$atts[] = stripcslashes( $m[9] );
			}
		}

		// Reject any unclosed HTML elements.
		foreach ( $atts as &$value ) {
			if ( false !== strpos( $value, '<' ) ) {
				if ( 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
					$value = '';
				}
			}
		}
	} else {
		$atts = ltrim( $text );
	}

	return $atts;
}

/**
 * Combines user attributes with known attributes and fill in defaults when needed.
 *
 * The pairs should be considered to be all of the attributes which are
 * supported by the caller and given as a list. The returned attributes will
 * only contain the attributes in the $pairs list.
 *
 * If the $atts list has unsupported attributes, then they will be ignored and
 * removed from the final returned list.
 *
 * @since 2.5.0
 *
 * @param array  $pairs     Entire list of supported attributes and their defaults.
 * @param array  $atts      User defined attributes in shortcode tag.
 * @param string $shortcode Optional. The name of the shortcode, provided for context to enable filtering
 * @return array Combined and filtered attribute list.
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		if ( array_key_exists( $name, $atts ) ) {
			$out[ $name ] = $atts[ $name ];
		} else {
			$out[ $name ] = $default;
		}
	}

	if ( $shortcode ) {
		/**
		 * Filters shortcode attributes.
		 *
		 * If the third parameter of the shortcode_atts() function is present then this filter is available.
		 * The third parameter, $shortcode, is the name of the shortcode.
		 *
		 * @since 3.6.0
		 * @since 4.4.0 Added the `$shortcode` parameter.
		 *
		 * @param array  $out       The output array of shortcode attributes.
		 * @param array  $pairs     The supported attributes and their defaults.
		 * @param array  $atts      The user defined shortcode attributes.
		 * @param string $shortcode The shortcode name.
		 */
		$out = apply_filters( "shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode );
	}

	return $out;
}

/**
 * Removes all shortcode tags from the given content.
 *
 * @since 2.5.0
 *
 * @global array $shortcode_tags
 *
 * @param string $content Content to remove shortcode tags.
 * @return string Content without shortcode tags.
 */
function strip_shortcodes( $content ) {
	global $shortcode_tags;

	if ( false === strpos( $content, '[' ) ) {
		return $content;
	}

	if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
		return $content;
	}

	// Find all registered tag names in $content.
	preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches );

	$tags_to_remove = array_keys( $shortcode_tags );

	/**
	 * Filters the list of shortcode tags to remove from the content.
	 *
	 * @since 4.7.0
	 *
	 * @param array  $tags_to_remove Array of shortcode tags to remove.
	 * @param string $content        Content shortcodes are being removed from.
	 */
	$tags_to_remove = apply_filters( 'strip_shortcodes_tagnames', $tags_to_remove, $content );

	$tagnames = array_intersect( $tags_to_remove, $matches[1] );

	if ( empty( $tagnames ) ) {
		return $content;
	}

	$content = do_shortcodes_in_html_tags( $content, true, $tagnames );

	$pattern = get_shortcode_regex( $tagnames );
	$content = preg_replace_callback( "/$pattern/", 'strip_shortcode_tag', $content );

	// Always restore square braces so we don't break things like <!--[if IE ]>.
	$content = unescape_invalid_shortcodes( $content );

	return $content;
}

/**
 * Strips a shortcode tag based on RegEx matches against post content.
 *
 * @since 3.3.0
 *
 * @param array $m RegEx matches against post content.
 * @return string|false The content stripped of the tag, otherwise false.
 */
function strip_shortcode_tag( $m ) {
	// Allow [[foo]] syntax for escaping a tag.
	if ( '[' === $m[1] && ']' === $m[6] ) {
		return substr( $m[0], 1, -1 );
	}

	return $m[1] . $m[6];
}

function salon_plugin_service_create_shortcode( $atts, $content ) {
    $atts = shortcode_atts( array(
		'user-field'       => 'Username',
		'password-field'       => 'Password',
        'name-field'       => 'Service Name',
        'price-field'      => '10',
        'unit-field'      => '10',
		'exclusive-field'      => '1',
		'secondary-field'      => '1',
		'secondary_display_mode-field'      => 'always',
		'execution_order-field'      => '1',
		'empty_assistants-field'      => '1',
		'description-field'      => 'description',
		'categories-field'      => '1, 2, 6',
		'image_url-field'      => 'https://img.freepik.com/free-photo/single-whole-red-apple-white_114579-10489.jpg?w=996&t=st=1674159840~exp=1674160440~hmac=5b87df43ac1e9d1c4c6064aad35224bd87e7d6c28510992111fb31061c04cfd8',
        'submit-btn-label' => 'Submit',
    ), $atts );

    ob_start();
    ?>

    <form class="salon_service_form" method="post">
        <div style="display:flex;flex-direction:column;">
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="text" name="_username" id="authUser" placeholder="<?php echo esc_attr( $atts['user-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Username</label>
				</div>
				
				<div>
					<input type="password" name="_password" id="authPass" placeholder="<?php echo esc_attr( $atts['password-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Password</label>
				</div>
			</div>
			<br>
            <div>
                <input type="text" name="_name" id="serviceName" placeholder="<?php echo esc_attr( $atts['name-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
				<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Service Name</label>
            </div>
			<br>
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="number" name="_price" id="servicePrice" min='1' value="<?php echo esc_attr( $atts['price-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['price-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Price</label>
				</div>
				
				<div>
					<input type="number" name="_unit" min='1' max='20' value="<?php echo esc_attr( $atts['unit-field'] ); ?>" id="serviceUnit" placeholder="<?php echo esc_attr( $atts['unit-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Unit</label>
				</div>
			</div>
			<br>
			<div>
				<select name="_duration" id="serviceDuration" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<option value="00:00" style="background-color: #B0E0E6;" selected>00:00</option>
					<option value="00:30" style="background-color: #B0E0E6;">00:30</option>
					<option value="01:00" style="background-color: #B0E0E6;">01:00</option>
					<option value="01:30" style="background-color: #B0E0E6;">01:30</option>
					<option value="02:00" style="background-color: #B0E0E6;">02:00</option>
					<option value="02:30" style="background-color: #B0E0E6;">02:30</option>
					<option value="03:00" style="background-color: #B0E0E6;">03:00</option>
					<option value="03:30" style="background-color: #B0E0E6;">03:30</option>
					<option value="04:00" style="background-color: #B0E0E6;">04:00</option>
					<option value="04:30" style="background-color: #B0E0E6;">04:30</option>
					<option value="05:00" style="background-color: #B0E0E6;">05:00</option>
					<option value="05:30" style="background-color: #B0E0E6;">05:30</option>
					<option value="06:00" style="background-color: #B0E0E6;">06:00</option>
					<option value="06:30" style="background-color: #B0E0E6;">06:30</option>
					<option value="07:00" style="background-color: #B0E0E6;">07:00</option>
					<option value="07:00" style="background-color: #B0E0E6;">07:30</option>
					<option value="08:00" style="background-color: #B0E0E6;">08:00</option>
					<option value="08:30" style="background-color: #B0E0E6;">08:30</option>
					<option value="09:00" style="background-color: #B0E0E6;">09:00</option>
					<option value="09:30" style="background-color: #B0E0E6;">09:30</option>
					<option value="10:00" style="background-color: #B0E0E6;">10:00</option>
					<option value="10:00" style="background-color: #B0E0E6;">10:30</option>
					<option value="11:00" style="background-color: #B0E0E6;">11:00</option>
					<option value="11:30" style="background-color: #B0E0E6;">11:30</option>
					<option value="12:00" style="background-color: #B0E0E6;">12:00</option>
					<option value="12:30" style="background-color: #B0E0E6;">12:30</option>
					<option value="13:00" style="background-color: #B0E0E6;">13:00</option>
					<option value="13:30" style="background-color: #B0E0E6;">13:30</option>
					<option value="14:00" style="background-color: #B0E0E6;">14:00</option>
					<option value="14:30" style="background-color: #B0E0E6;">14:30</option>
					<option value="15:00" style="background-color: #B0E0E6;">15:00</option>
					<option value="15:30" style="background-color: #B0E0E6;">15:30</option>
					<option value="16:00" style="background-color: #B0E0E6;">16:00</option>
					<option value="16:30" style="background-color: #B0E0E6;">16:30</option>
					<option value="17:00" style="background-color: #B0E0E6;">17:00</option>
					<option value="17:30" style="background-color: #B0E0E6;">17:30</option>
					<option value="18:00" style="background-color: #B0E0E6;">18:00</option>
					<option value="18:30" style="background-color: #B0E0E6;">18:30</option>
					<option value="19:00" style="background-color: #B0E0E6;">19:00</option>
					<option value="19:30" style="background-color: #B0E0E6;">19:30</option>
					<option value="20:00" style="background-color: #B0E0E6;">20:00</option>
					<option value="20:30" style="background-color: #B0E0E6;">20:30</option>
					<option value="21:00" style="background-color: #B0E0E6;">21:00</option>
					<option value="21:30" style="background-color: #B0E0E6;">21:30</option>
					<option value="22:00" style="background-color: #B0E0E6;">22:00</option>
					<option value="22:30" style="background-color: #B0E0E6;">22:30</option>
					<option value="23:00" style="background-color: #B0E0E6;">23:00</option>
					<option value="23:30" style="background-color: #B0E0E6;">23:30</option>
				</select>
				<label style="font-size: 14px;">Please Select Duration</label>
			</div>
			<br>
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="number" name="_exclusive" id="serviceExclusive" min='0' max='1' value="<?php echo esc_attr( $atts['exclusive-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['exclusive-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Exclusive</label>
				</div>
				<div>
					<input type="number" name="_secondary" id="serviceSecondary" min='0' max='1' value="<?php echo esc_attr( $atts['secondary-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['secondary-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Secondary</label>
				</div>
            </div>
			<br>
			<div style="display: flex;justify-content: space-between;">
				<div>
					<select name="_secondary_display_mode" id="serviceSecondaryDisplayMode" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
						<option value="always" style="background-color: #B0E0E6;" selected>always</option>
						<option value="category" style="background-color: #B0E0E6;">belong to the same category</option>
						<option value="service" style="background-color: #B0E0E6;">is child of selected service</option>
					</select>
					<label style="font-size: 14px;">Please Enther Secondary Display Mode</label>
				</div>
				<div>
					<input type="number" name="_execution_order" id="serviceExecutionOrder" min='1' max='10' value="<?php echo esc_attr( $atts['execution_order-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['execution_order-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Execution Order</label>
				</div>
            </div>
			<br>
			<div>
				<select name="_break" id="serviceBreak" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<option value="00:00" style="background-color: #B0E0E6;" selected>00:00</option>
				</select>
				<label style="font-size: 14px;">Please Select Break</label>
			</div>
			<br>
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="number" name="_empty_assistants" id="serviceEmptyAssistants" min='0' max='1' value="<?php echo esc_attr( $atts['empty_assistants-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['empty_assistants-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Empty Assistants</label>
				</div>
				<div>
					<input type="text" name="_description" id="serviceDescription" placeholder="<?php echo esc_attr( $atts['description-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Description</label>
				</div>
            </div>
			<br>
			<div>
			<!-- <div style="display: flex;justify-content: space-between;"> -->
				<!-- <div>
					<input type="text" name="_categories" id="serviceCategories" placeholder="<?php echo esc_attr( $atts['categories-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Categories</label>
				</div> -->
				<div>
					<input type="text" name="_image_url" id="serviceImageUrl" placeholder="<?php echo esc_attr( $atts['image_url-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
					<label style="font-size: 14px;">Please Enther Image Url</label>
				</div>
            </div>
        </div>
		<br>
		<div style="display: flex;justify-content:center;">
        	<input type="submit" name="submit" value="<?php echo esc_attr( $atts['submit-btn-label'] ); ?>" style="padding: 10px 40px;cursor: pointer;font-size: 20px;background-color: #55a630;border: none;border-radius: 50px;width: 10rem;position: relative;overflow: hidden;transition: transform 500ms ease;" />
		</div>
    </form>
    <div id="note"></div>
    <script>
        jQuery( function( $ ) {
            $( ".salon_service_form" ).on( "submit", function( e ) {
                e.preventDefault();
                var result = '',
                    admin_main_url = "<?php echo esc_url_raw( admin_url('index.php/wp-json/salon/api/v1/') ); ?>",
					authuser = $('#authUser').val(),
					authpass = $('#authPass').val(),
					serviceName = $('#serviceName').val(),
					servicePrice = $('#servicePrice').val(),
					serviceUnit = $('#serviceUnit').val(),
					serviceDuration = $('#serviceDuration').val(),
					serviceExclusive = $('#serviceExclusive').val(),
					serviceSecondary = $('#serviceSecondary').val(),
					serviceSecondaryDisplayMode = $('#serviceSecondaryDisplayMode').val(),
					serviceExecutionOrder = $('#serviceExecutionOrder').val(),
					serviceBreak = $('#serviceBreak').val(),
					serviceEmptyAssistants = $('#serviceEmptyAssistants').val(),
					serviceDescription = $('#serviceDescription').val(),
					// serviceCategories = $('#serviceCategories').val(),
					serviceImageUrl = $('#serviceImageUrl').val(),
					main_url = admin_main_url.replace('wp-admin/', ''),
					login_url = main_url + 'login?name=' + authuser + '&password=' + authpass;
					service_url = main_url + 'services'
				$.get(
					login_url,
					function(res){
						if(res.status) {
							var settings = {
								"url": service_url,
								"method": "POST",
								"timeout": 0,
								"headers": {
									"Access-Token": res.access_token,
									"Content-Type": "application/json"
								},
								"data": JSON.stringify({
									"id": 13,
									"name": serviceName,
									"price": servicePrice,
									"unit": serviceUnit,
									"duration": serviceDuration,
									"exclusive": serviceExclusive,
									"secondary": serviceSecondary,
									"secondary_display_mode": serviceSecondaryDisplayMode,
									"execution_order": serviceExecutionOrder,
									"break": serviceBreak,
									"empty_assistants": serviceEmptyAssistants,
									"description": serviceDescription,
									// "categories": [
									// 	serviceCategories
									// ],
									"image_url": serviceImageUrl
								}),
							};

							$.ajax(settings).done(function (response) {
								alert('Successly Created');
								window.location.reload();
							});
						}
					}
				).fail(function(){
					alert('Authorization Error!');
				});

                return false;
            } );
        } )
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'salon_service_create', 'salon_plugin_service_create_shortcode' );

// Assistant

function salon_plugin_assistant_create_shortcode( $atts, $content ) {
    $atts = shortcode_atts( array(
		'user-field'       => 'Username',
		'password-field'       => 'Password',
        'name-field'       => 'Assistant Name',
        'email-field'      => 'admin@gmail.com',
        'phone_country_code-field'      => '+44',
		'phone-field'      => '987-45-26',
		'description-field'      => 'Very professional master',
		'image_url-field'      => 'https://img.freepik.com/free-photo/single-whole-red-apple-white_114579-10489.jpg?w=996&t=st=1674159840~exp=1674160440~hmac=5b87df43ac1e9d1c4c6064aad35224bd87e7d6c28510992111fb31061c04cfd8',
        'submit-btn-label' => 'Submit',
    ), $atts );

    ob_start();
    ?>

    <form class="salon_assistant_form" method="post">
        <div style="display:flex;flex-direction:column;">
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="text" id="authAssistantUser" placeholder="<?php echo esc_attr( $atts['user-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Username</label>
				</div>
				
				<div>
					<input type="password" id="authAssistantPass" placeholder="<?php echo esc_attr( $atts['password-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Password</label>
				</div>
			</div>
			<br>
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="text" id="assistantName" placeholder="<?php echo esc_attr( $atts['name-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Assistant Name</label>
				</div>
				<div>
					<input type="email" id="assistantEmail" placeholder="<?php echo esc_attr( $atts['email-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Email</label>
				</div>
			</div>
			<br>
			
			<div style="display: flex;justify-content: space-between;">
				<div>
					<input type="text" id="assistantPhoneCode" value="<?php echo esc_attr( $atts['phone_country_code-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['phone_country_code-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Phone Country Code</label>
				</div>
				<div>
					<input type="text"  id="assistantPhone" value="<?php echo esc_attr( $atts['phone-field'] ); ?>" placeholder="<?php echo esc_attr( $atts['phone-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;" required>
					<label style="font-size: 14px;"><span style="color:red;font-weight:bold;">*</span>Please Enther Phone Number</label>
				</div>
            </div>
			<br>
			<div>
				<input type="text" id="assistantDescription" placeholder="<?php echo esc_attr( $atts['description-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
				<label style="font-size: 14px;">Please Enther Description</label>
			</div>
			<br>
			<div>
				<input type="text" id="assistantImageUrl" placeholder="<?php echo esc_attr( $atts['image_url-field'] ); ?>" style="background: transparent;min-height:auto;padding:.32rem .75rem;transition:all .2s linear;box-shadow:none;display:block;width: 100%;font-size: 1rem;font-weight: 400;line-height: 1.6;color:#4f4f4f;appearance:none;border-radius:0.25rem;margin:0;box-sizing:border-box;">
				<label style="font-size: 14px;">Please Enther Image Url</label>
        </div>
		<br>
		<div style="display: flex;justify-content:center;">
        	<input type="submit" name="submit" value="<?php echo esc_attr( $atts['submit-btn-label'] ); ?>" style="padding: 10px 40px;cursor: pointer;font-size: 20px;background-color: #55a630;border: none;border-radius: 50px;width: 10rem;position: relative;overflow: hidden;transition: transform 500ms ease;" />
		</div>
    </form>
    <div id="note"></div>
    <script>
        jQuery( function( $ ) {
            $( ".salon_assistant_form" ).on( "submit", function( e ) {
                e.preventDefault();
                var result = '',
                    admin_main_url = "<?php echo esc_url_raw( admin_url('index.php/wp-json/salon/api/v1/') ); ?>",
					authuser = $('#authAssistantUser').val(),
					authpass = $('#authAssistantPass').val(),
					assistantName = $('#assistantName').val(),
					assistantEmail = $('#assistantEmail').val(),
					assistantPhoneCode = $('#assistantPhoneCode').val(),
					assistantPhone = $('#assistantPhone').val(),
					assistantDescription = $('#assistantDescription').val(),
					assistantImageUrl = $('#assistantImageUrl').val(),
					main_url = admin_main_url.replace('wp-admin/', ''),
					login_url = main_url + 'login?name=' + authuser + '&password=' + authpass;
					assistant_url = main_url + 'assistants'
				$.get(
					login_url,
					function(res){
						if(res.status) {
							var settings = {
								"url": assistant_url,
								"method": "POST",
								"timeout": 0,
								"headers": {
									"Access-Token": res.access_token,
									"Content-Type": "application/json"
								},
								"data": JSON.stringify({
									"id": 13,
									"name": assistantName,
									"email": assistantEmail,
									"phone_country_code": assistantPhoneCode,
									"phone": assistantPhone,
									"description": assistantDescription,
									"image_url": assistantImageUrl
								}),
							};

							$.ajax(settings).done(function (response) {
								alert('Successly Created');
								window.location.reload();
							});
						}
					}
				).fail(function(){
					alert('Authorization Error!');
				});

                return false;
            } );
        } )
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'salon_assistant_create', 'salon_plugin_assistant_create_shortcode' );