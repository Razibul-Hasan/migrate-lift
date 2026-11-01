import { Path, Rect, SVG } from '@wordpress/primitives';

export default (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: "none" } } stroke="currentColor" strokeWidth="1.5">
		<Rect x="6.5" y="8" width="3" height="8" fill="currentColor" stroke="none" />
		<Rect x="14.5" y="8" width="3" height="8" fill="currentColor" stroke="none" />
		<Path d="M4.25 4V20" />
		<Path d="M19.75 4V20" />
	</SVG>
);
