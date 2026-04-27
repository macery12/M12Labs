module.exports = {
    plugins: [
        require('postcss-import'),
        // We want to make use of nesting following the CSS Nesting spec, and not the
        // SASS style nesting.
        //
        // @see https://github.com/csstools/postcss-plugins/tree/main/plugins/postcss-nesting
        require('tailwindcss/nesting')(require('postcss-nesting')),
        require('tailwindcss'),
        // postcss-preset-env handles autoprefixing — autoprefixer is not needed separately.
        require('postcss-preset-env')({
            features: {
                'nesting-rules': false,
            },
        }),
    ],
};
